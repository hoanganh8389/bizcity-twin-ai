<?php
/**
 * Bizcity Twin AI — TwinChat Stream Handler
 *
 * Server-Sent Events pipeline for `/bizcity-twinchat/v1/chat/{notebook}/stream`.
 *
 *  Steps (per Sprint 4 spec §8.2):
 *   1. Auth check.
 *   2. Cost Guard (Contract 5).
 *   3. emit status=analyzing.
 *   4. Build context via Context Builder (Contract 7+9).
 *   5. emit sources + kg_highlight.
 *   6. emit status=generating.
 *   7. Stream LLM tokens via BizCity_LLM_Client::chat_stream() (Contract 2).
 *   8. emit complete + persist message row.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 * @since 2026-05-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Stream_Handler {

	private static $instance = null;

	/**
	 * Sprint 4.4h — step id + start time tracker for typed AgentStep events.
	 * Reset per pipeline run.
	 */
	private $step_ids    = [];
	private $step_starts = [];

	/**
	 * Phase 0.12 Wave B+ PR-B+1 — Twin Event Stream lifecycle for the current
	 * SSE turn. Set in handle() before run_pipeline() and unset in finally.
	 *
	 * @var array{trace_id:string,started_at:float,success:bool,event_uuid:string}|null
	 */
	private $event_stream_turn = null;

	/**
	 * Closure forwarding `bizcity_twin_event_v2` actions to the active SSE
	 * connection as `event: twin_event` frames. Stored to allow remove_action.
	 *
	 * @var callable|null
	 */
	private $event_stream_forwarder = null;

	/**
	 * Phase 0.12 Wave B+ — closure mirroring TwinDebug pipeline_log entries
	 * to the Event Stream as `decision` events. Stored to allow remove_action.
	 *
	 * @var callable|null
	 */
	private $event_stream_debug_bridge = null;

	/**
	 * CF/Nginx idle-timeout guard — timestamp of last byte sent on the SSE wire.
	 * `maybe_heartbeat()` sends `: heartbeat\n\n` when idle ≥ 30 s so Cloudflare
	 * (100 s idle limit) never sees a gap long enough to drop the connection.
	 *
	 * @var float
	 */
	private $heartbeat_ts = 0.0;

	/**
	 * `bizcity_intent_pipeline_log` hook registered during open_sse_stream().
	 * Stored so we can remove_action precisely in close_sse_stream().
	 *
	 * @var callable|null
	 */
	private $heartbeat_hook = null;

	/**
	 * Phase 0.12 Wave B+ PR-T2 — coalesce buffer for `assistant_streaming_chunk`
	 * envelopes. Without throttling each LLM token would emit a new envelope +
	 * persist a row, which would flood `bizcity_twin_event_stream`. We coalesce
	 * by chunk_kind and flush on size (~80 chars) or time (~500 ms) thresholds.
	 * Reset per turn at the top of run_pipeline().
	 *
	 * @var array{last_ts:float,pending_content:string,pending_reasoning:string}
	 */
	private $chunk_emit_state = [ 'last_ts' => 0.0, 'pending_content' => '', 'pending_reasoning' => '' ];

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Main entry — invoked from REST controller.
	 *
	 * Writes SSE directly to the output stream and exits.
	 *
	 * @param array $args
	 * @return void
	 */
	public function handle( array $args ) {
		$args = array_merge( [
			'notebook_id'     => 0,
			'session_id'      => '',
			'user_message'    => '',
			'history'         => [],
			'source_ids'      => [],
			'use_kg'          => true,
			'enable_thinking' => false,
			// Wave 0.18.5c — composer @-mention payload (or sticky restore).
			'target_guru'     => null,
		], $args );

		$user_id     = get_current_user_id();
		$notebook_id = (int) $args['notebook_id'];
		$session_id  = (string) $args['session_id'];
		if ( $session_id === '' ) {
			$session_id = wp_generate_uuid4();
		}

		// Sprint 4.7e — Twin Agent delegation (opt-in, feature-flagged).
		// Khi flag bật + Twin_Agent có sẵn, bypass legacy pre-retrieve pipeline
		// và để LLM tự quyết khi nào gọi tools.
		$use_twin_agent = ( defined( 'BIZCITY_TWINCHAT_USE_TWIN_AGENT' ) && BIZCITY_TWINCHAT_USE_TWIN_AGENT )
			|| ( get_option( 'bizcity_twinchat_use_twin_agent', false ) === '1' )
			|| apply_filters( 'bizcity_twinchat_use_twin_agent', false, $args );

		if ( $use_twin_agent && class_exists( 'BizCity_Twin_Agent' ) ) {
			// Phase 0.12 Wave B+ — prepare turn (forwarder + trace_id) BEFORE
			// SSE opens; turn_start is dispatched from inside handle_via_twin_agent
			// AFTER BizCity_Twin_SSE_Writer flushes headers.
			$this->prepare_event_stream_turn( $user_id, $notebook_id, $session_id, $args );
			try {
				$this->handle_via_twin_agent( $args, $user_id, $notebook_id, $session_id );
				if ( is_array( $this->event_stream_turn ) ) {
					$this->event_stream_turn['success'] = true;
				}
			} catch ( \Throwable $e ) {
				error_log( '[TwinChat] twin_agent path uncaught: ' . $e->getMessage() );
				if ( is_array( $this->event_stream_turn ) ) {
					$this->event_stream_turn['success']     = false;
					$this->event_stream_turn['error_msg']   = $e->getMessage();
					$this->event_stream_turn['error_where'] = basename( $e->getFile() ) . ':' . $e->getLine();
				}
			} finally {
				$this->end_event_stream_turn();
			}
			return;
		}

		$this->open_sse_stream();

		// [2026-06-05 Johnny Chu] PHASE-MEMBERSHIP BE-3B — quota gate before pipeline
		$can_send = apply_filters( 'bizcity_twinchat_can_send_message', true, $user_id );
		if ( is_wp_error( $can_send ) ) {
			// [2026-06-09 Johnny Chu] PHASE-D D-BE-QUOTA — R-MEMBERSHIP-QUOTA-ERROR-UX:
			// Forward đủ error_data (plan, limit, used, resets_at, hint, help_code)
			// để FE QuotaErrorBanner render inline plan info đúng chuẩn.
			$d = is_array( $can_send->get_error_data() ) ? $can_send->get_error_data() : array();
			$this->emit( 'error', array(
				'message'        => $can_send->get_error_message(),
				'code'           => $can_send->get_error_code(),
				'hint'           => isset( $d['hint'] )       ? $d['hint']       : 'Nâng cấp gói để tiếp tục.',
				'help_code'      => isset( $d['help_code'] )  ? $d['help_code']  : 'membership_quota_exceeded',
				'quota_exceeded' => true,
				'plan'           => isset( $d['plan'] )        ? $d['plan']       : '',
				'plan_label'     => isset( $d['plan_label'] )  ? $d['plan_label'] : '',
				'feature'        => isset( $d['feature'] )     ? $d['feature']    : 'chat_msgs_per_day',
				'limit'          => isset( $d['limit'] )       ? (int) $d['limit']  : 0,
				'used'           => isset( $d['used'] )        ? (int) $d['used']   : 0,
				'resets_at'      => isset( $d['resets_at'] )   ? $d['resets_at']  : 'ngày mai 00:00 UTC',
			) );
			return;
		}
		$this->begin_event_stream_turn( $user_id, $notebook_id, $session_id, $args );

		try {
			$this->run_pipeline( $args, $user_id, $notebook_id, $session_id );
			$this->event_stream_turn['success'] = true;
			// [2026-06-05 Johnny Chu] PHASE-MEMBERSHIP BE-3B — increment chat usage counter
			do_action( 'bizcity_twinchat_message_sent', $user_id );
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] stream pipeline error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
			$this->emit( 'error', [
				'message' => $e->getMessage(),
				'where'   => basename( $e->getFile() ) . ':' . $e->getLine(),
			] );
			if ( is_array( $this->event_stream_turn ) ) {
				$this->event_stream_turn['success']     = false;
				$this->event_stream_turn['error_msg']   = $e->getMessage();
				$this->event_stream_turn['error_where'] = basename( $e->getFile() ) . ':' . $e->getLine();
			}
		} finally {
			$this->end_event_stream_turn();
		}

		$this->close_sse_stream();
	}

	private function run_pipeline( array $args, $user_id, $notebook_id, $session_id ) {
		// Reset per-run step tracker.
		$this->step_ids    = [];
		$this->step_starts = [];

		// PR-T2 — reset coalesce buffer for assistant_streaming_chunk emit throttle.
		$this->chunk_emit_state = [ 'last_ts' => microtime( true ), 'pending_content' => '', 'pending_reasoning' => '' ];

		// 1. Auth.
		if ( $user_id <= 0 ) {
			$this->emit( 'error', [ 'message' => 'Authentication required.' ] );
			return;
		}

		// 2. Cost Guard (Contract 5).
		if ( class_exists( 'BizCity_KG_Cost_Guard' ) ) {
			$guard = BizCity_KG_Cost_Guard::instance()->can_extract( $user_id, 1 );
			if ( is_wp_error( $guard ) ) {
				$this->emit( 'error', [
					'message' => $guard->get_error_message(),
					'code'    => $guard->get_error_code(),
				] );
				return;
			}
		}

		// 3. status=analyzing — emit IMMEDIATELY so the FE timeline shows life
		//    right when the user hits send (before any blocking KG work).
		$t0 = microtime( true );
		$this->start_step( 'analyzing', 'Đang phân tích câu hỏi...' );

		// Sprint 4.10.5 — Hoist `kg_retrieving` BEFORE the (slow) Context Builder
		// call. Previously this step was started AFTER build() returned, which
		// meant the user stared at a frozen "analyzing" step for the entire KG
		// retrieval (entity extract → embed → vector search → subgraph build),
		// then saw sources/graph_focus dump in all at once. Now they get a live
		// "Đang tìm kiếm Knowledge Graph..." step while the work happens.
		$kg_step_emitted = false;
		if ( (bool) $args['use_kg'] && $notebook_id > 0 ) {
			$this->complete_step( 'analyzing' );
			$this->start_step( 'kg_retrieving', 'Đang tìm kiếm Knowledge Graph...' );
			$kg_step_emitted = true;

			// PR-T2 — emit retrieval(start) on the canonical Twin Event Stream.
			$this->dispatch_turn_event( 'retrieval', [
				'scope' => 'kg',
				'query' => (string) $args['user_message'],
				'phase' => 'start',
			] );
		}

		// 4. Context build (slow: KG retrieval + subgraph + system prompt).
		$ctx = BizCity_TwinChat_Context_Builder::instance()->build( [
			'notebook_id'     => $notebook_id,
			'user_id'         => $user_id,
			'session_id'      => $session_id,
			'user_message'    => (string) $args['user_message'],
			'history'         => is_array( $args['history'] ) ? $args['history'] : [],
			'use_kg'          => (bool) $args['use_kg'],
			'source_ids'      => is_array( $args['source_ids'] ) ? $args['source_ids'] : [],
			'enable_thinking' => (bool) $args['enable_thinking'],
		] );

		/* Wave 2.8b TBR.MEM-N2 (2026-05-23) — Layer 0.5 Memory Recall parity.
		 * Founder mandate: notebook chat phải có memory layer y hệt master Ask Brain,
		 * đi cùng qua `bizcity_twin_event_stream` (R-EVT-2). Inject memory_block vào
		 * top of system_prompt + dispatch `memory_recall` event với cause-context
		 * surface='twinchat-notebook'. notebook_id scope passed xuống Memory_Recall
		 * để prefer rows có key=`nb:<id>:*` nếu Memory_Recall implement scope-aware.
		 * Idempotent + failure-tolerant: không block turn khi recall miss. */
		if ( $user_id > 0 && class_exists( 'BizCity_TwinBrain_Memory_Recall' ) ) {
			try {
				$mem_res = BizCity_TwinBrain_Memory_Recall::instance()->collect(
					(int) $user_id,
					(string) $args['user_message'],
					[
						'notebook_id' => (int) $notebook_id,
						'session_id'  => (string) $session_id,
						'surface'     => 'twinchat-notebook',
					]
				);
				$mem_block = (string) ( $mem_res['block'] ?? '' );
				if ( $mem_block !== '' ) {
					/* Wave 2.8d D6.9d — wrap memory block with explicit instruction
					 * (mirrors twin_agent path above) so LLM stops defaulting to
					 * "tôi không có khả năng ghi nhớ". */
					$wrapped  = "### BEGIN USER MEMORY (HIGH PRIORITY — PERSONAL FACTS ABOUT THE CURRENT USER)\n";
					$wrapped .= "The following lines are persistent facts the user has TOLD this assistant in previous conversations.\n";
					$wrapped .= "RULES (override any generic 'I cannot remember' default):\n";
					$wrapped .= "1. You DO have memory of this user — these facts are it. NEVER say 'tôi không nhớ', 'tôi không có khả năng ghi nhớ', or any equivalent denial.\n";
					$wrapped .= "2. When the user asks about themselves (tên, sở thích, công việc, dự án, thói quen…), answer DIRECTLY using these facts.\n";
					$wrapped .= "3. When you state a fact drawn from this block, cite it with `[mem:U#<id>]` exactly as listed (the FE renders these as clickable chips).\n";
					$wrapped .= "4. If a fact is missing here, you may say so politely, but do NOT deny having memory at all.\n";
					$wrapped .= "---\n" . $mem_block . "\n### END USER MEMORY\n\n";
					if ( ! empty( $ctx['system_prompt'] ) ) {
						$ctx['system_prompt'] = $wrapped . (string) $ctx['system_prompt'];
					} else {
						$ctx['system_prompt'] = $wrapped;
					}
				}
				$counts = (array) ( $mem_res['counts'] ?? [ 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0 ] );
				$mem_recall_payload = [
					'surface'     => 'twinchat-notebook',
					'notebook_id' => (int) $notebook_id,
					'counts'      => $counts,
					'citations'   => (array) ( $mem_res['citations'] ?? [] ),
					'block_len'   => mb_strlen( $mem_block ),
					'latency_ms'  => (int) ( $mem_res['latency_ms'] ?? 0 ),
				];
				// Emit on legacy SSE channel so existing FE reducer (case 'memory_recall')
				// hiển thị violet step trong timeline.
				$this->emit( 'memory_recall', $mem_recall_payload );
				// Mirror vào `bizcity_twin_event_stream` (R-EVT-2) cho forensics.
				$this->dispatch_turn_event( 'memory_recall', $mem_recall_payload );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinChat][memory_recall][error] ' . $e->getMessage() );
				}
			}
		}

		// 5. KG retrieving + sources + kg_highlight.
		if ( ! empty( $ctx['kg_summary']['sources'] ) || ! empty( $ctx['kg_summary']['cited_entities'] ) ) {
			if ( ! $kg_step_emitted ) {
				$this->complete_step( 'analyzing' );
				$this->start_step( 'kg_retrieving', 'Đang tìm kiếm Knowledge Graph...' );
				$kg_step_emitted = true;

				// PR-T2 — late retrieval(start) when KG was skipped at top-of-pipeline
				// but Context Builder still produced sources (defensive).
				$this->dispatch_turn_event( 'retrieval', [
					'scope' => 'kg',
					'query' => (string) $args['user_message'],
					'phase' => 'start',
				] );
			}

			if ( ! empty( $ctx['kg_summary']['sources'] ) ) {
				$this->emit( 'sources', [ 'sources' => $ctx['kg_summary']['sources'] ] );
			}

			// Sprint 4.4i — emit KG entity citations as a parallel reference list.
			if ( ! empty( $ctx['kg_summary']['kg_citations'] ) ) {
				$this->emit( 'kg_citations', [ 'kg_citations' => $ctx['kg_summary']['kg_citations'] ] );
			}

			$entity_ids   = [];
			$relation_ids = [];
			if ( isset( $ctx['kg_summary']['subgraph']['nodes'] ) && is_array( $ctx['kg_summary']['subgraph']['nodes'] ) ) {
				foreach ( $ctx['kg_summary']['subgraph']['nodes'] as $n ) {
					if ( isset( $n['id'] ) ) {
						$entity_ids[] = (int) $n['id'];
					}
				}
			}
			if ( isset( $ctx['kg_summary']['subgraph']['links'] ) && is_array( $ctx['kg_summary']['subgraph']['links'] ) ) {
				foreach ( $ctx['kg_summary']['subgraph']['links'] as $l ) {
					if ( isset( $l['id'] ) ) {
						$relation_ids[] = (int) $l['id'];
					}
				}
			}
			if ( ! empty( $entity_ids ) || ! empty( $relation_ids ) ) {
				$this->emit( 'kg_highlight', [
					'entity_ids'   => array_values( array_unique( $entity_ids ) ),
					'relation_ids' => array_values( array_unique( $relation_ids ) ),
				] );
			}

			// PR-T2 — emit retrieval(complete) carrying sources + kg_highlight + citations
			// in a SINGLE envelope so the FE event reducer can fan out to all three
			// targets (sources_found chips, KG highlight nodes, citation list).
			$top_sources = is_array( $ctx['kg_summary']['sources'] ?? null )
				? array_values( $ctx['kg_summary']['sources'] )
				: [];
			$kg_citations_pl = is_array( $ctx['kg_summary']['kg_citations'] ?? null )
				? array_values( $ctx['kg_summary']['kg_citations'] )
				: [];
			$this->dispatch_turn_event( 'retrieval', [
				'scope'        => 'kg',
				'query'        => (string) $args['user_message'],
				'phase'        => 'complete',
				'top_sources'  => $top_sources,
				'kg_citations' => $kg_citations_pl,
				'kg_highlight' => [
					'entity_ids'   => array_values( array_unique( $entity_ids ) ),
					'relation_ids' => array_values( array_unique( $relation_ids ) ),
				],
				'counts'       => [
					'sources'  => count( $top_sources ),
					'entities' => count( $entity_ids ),
					'relations'=> count( $relation_ids ),
				],
			] );
		} elseif ( $kg_step_emitted ) {
			// PR-T2 — KG was attempted but returned nothing; still emit complete
			// so the FE timeline can close the kg_retrieving step.
			$this->dispatch_turn_event( 'retrieval', [
				'scope'       => 'kg',
				'query'       => (string) $args['user_message'],
				'phase'       => 'complete',
				'top_sources' => [],
				'counts'      => [ 'sources' => 0, 'entities' => 0, 'relations' => 0 ],
			] );
		}

		// 6. status=generating.
		$this->complete_step( 'kg_retrieving' );
		$this->complete_step( 'analyzing' ); // no-op if already completed
		$this->start_step( 'generating', 'Đang viết câu trả lời...' );

		// 7. LLM streaming via Smart Gateway client (Contract 2).
		$messages    = $this->build_messages( $ctx );
		$accumulated = '';
		$thinking    = '';
		$buffer_state = [ 'in_think' => false, 'tail' => '' ];
		$enable_thinking_split = ! empty( $args['enable_thinking'] );

		$on_chunk = function ( $chunk ) use ( &$accumulated, &$thinking, &$buffer_state, $enable_thinking_split ) {
			// Reset CF idle timer on every incoming chunk (covers TTFT wait gap).
			$this->maybe_heartbeat();
			$text = is_string( $chunk ) ? $chunk : ( isset( $chunk['delta'] ) ? (string) $chunk['delta'] : '' );
			if ( $text === '' ) {
				return;
			}

			if ( ! $enable_thinking_split ) {
				$accumulated .= $text;
				$this->emit( 'token', [ 'text' => $text ] );
				$this->emit_streaming_chunk( 'content', $text );
				return;
			}

			// Nexus / Deepseek-style <think>...</think> splitter.
			// Carry over any partial tag that may straddle chunk boundaries.
			$work = $buffer_state['tail'] . $text;
			$buffer_state['tail'] = '';

			while ( $work !== '' ) {
				if ( $buffer_state['in_think'] ) {
					$end = strpos( $work, '</think>' );
					if ( $end === false ) {
						// Hold last 8 chars to detect split tag on next chunk.
						$safe = max( 0, strlen( $work ) - 8 );
						$thinking_part = substr( $work, 0, $safe );
						$buffer_state['tail'] = substr( $work, $safe );
						if ( $thinking_part !== '' ) {
							$thinking .= $thinking_part;
							$this->emit( 'thinking', [ 'text' => $thinking_part ] );
							$this->emit_streaming_chunk( 'reasoning', $thinking_part );
						}
						$work = '';
					} else {
						$thinking_part = substr( $work, 0, $end );
						if ( $thinking_part !== '' ) {
							$thinking .= $thinking_part;
							$this->emit( 'thinking', [ 'text' => $thinking_part ] );
							$this->emit_streaming_chunk( 'reasoning', $thinking_part );
						}
						$work = substr( $work, $end + strlen( '</think>' ) );
						$buffer_state['in_think'] = false;
					}
				} else {
					$start = strpos( $work, '<think>' );
					if ( $start === false ) {
						$safe = max( 0, strlen( $work ) - 7 );
						$visible = substr( $work, 0, $safe );
						$buffer_state['tail'] = substr( $work, $safe );
						if ( $visible !== '' ) {
							$accumulated .= $visible;
							$this->emit( 'token', [ 'text' => $visible ] );
							$this->emit_streaming_chunk( 'content', $visible );
						}
						$work = '';
					} else {
						$visible = substr( $work, 0, $start );
						if ( $visible !== '' ) {
							$accumulated .= $visible;
							$this->emit( 'token', [ 'text' => $visible ] );
							$this->emit_streaming_chunk( 'content', $visible );
						}
						$work = substr( $work, $start + strlen( '<think>' ) );
						$buffer_state['in_think'] = true;
					}
				}
			}
		};

		$llm_options = [
			'purpose'     => 'chat',
			'temperature' => 0.5,
			'max_tokens'  => 1500,
		];

		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			$this->emit( 'error', [ 'message' => 'LLM client (BizCity_LLM_Client) not loaded.' ] );
			return;
		}

		// PR-T2 — emit llm_request right before the (potentially long) call so the
		// FE timeline can transition kg_retrieving → generating in real time.
		$llm_purpose = isset( $llm_options['purpose'] ) ? (string) $llm_options['purpose'] : 'chat';
		$this->dispatch_turn_event( 'llm_request', [
			'model'       => apply_filters( 'bizcity_llm_default_model', '', $llm_purpose ) ?: 'auto',
			'purpose'     => $llm_purpose,
			'temperature' => isset( $llm_options['temperature'] ) ? (float) $llm_options['temperature'] : null,
			'max_tokens'  => isset( $llm_options['max_tokens'] ) ? (int) $llm_options['max_tokens'] : null,
			'message_count' => is_array( $messages ) ? count( $messages ) : 0,
		] );

		$llm_result = BizCity_LLM_Client::instance()->chat_stream( $messages, $llm_options, $on_chunk );

		if ( ! is_array( $llm_result ) || empty( $llm_result['success'] ) ) {
			$err = is_array( $llm_result ) && isset( $llm_result['error'] ) ? (string) $llm_result['error'] : 'LLM call failed.';
			// PR-T2 — mirror failure to canonical Event Stream.
			$this->dispatch_turn_event( 'llm_error', [
				'error_msg'  => $err,
				'error_code' => is_array( $llm_result ) && isset( $llm_result['error_code'] ) ? (string) $llm_result['error_code'] : '',
				'purpose'    => $llm_purpose,
			] );
			// [2026-06-09 Johnny Chu] PHASE-D D-BE-QUOTA — Hub quota (Layer 1) hit.
			// Emit structured SSE error with hub_quota=true so FE renders QuotaErrorBanner
			// with the correct variant ("Liên hệ admin") instead of generic StreamErrorBanner.
			$is_hub_quota = is_array( $llm_result ) && ! empty( $llm_result['quota_exhausted'] );
			if ( $is_hub_quota ) {
				$tier = is_array( $llm_result ) && isset( $llm_result['tier'] ) ? (string) $llm_result['tier'] : 'free';
				$this->emit( 'error', array(
					'message'        => 'Hệ thống AI đã vượt giới hạn tháng (Layer 1 Hub). Liên hệ admin để nâng cấp gói dịch vụ.',
					'code'           => 'quota_exceeded',
					'quota_exceeded' => true,
					'hub_quota'      => true,
					'plan'           => $tier,
					'plan_label'     => strtoupper( $tier ) . ' (Hub)',
					'feature'        => 'llm_chat',
					'limit'          => 0,
					'used'           => 0,
					'resets_at'      => '',
					'hint'           => 'Liên hệ admin site để nâng cấp gói BizCity Hub.',
					'help_code'      => 'hub_quota_exhausted',
				) );
			} else {
				$this->emit( 'error', array( 'message' => $err ) );
			}
			return;
		}

		// PR-T2 — flush any pending streaming chunks then announce llm_response.
		$this->flush_streaming_chunks();
		$this->dispatch_turn_event( 'llm_response', [
			'model_used'        => isset( $llm_result['model'] ) ? (string) $llm_result['model'] : 'auto',
			'finish_reason'     => isset( $llm_result['finish_reason'] ) ? (string) $llm_result['finish_reason'] : '',
			'prompt_tokens'     => isset( $llm_result['usage']['prompt_tokens'] ) ? (int) $llm_result['usage']['prompt_tokens'] : 0,
			'completion_tokens' => isset( $llm_result['usage']['completion_tokens'] ) ? (int) $llm_result['usage']['completion_tokens'] : 0,
			'total_tokens'      => isset( $llm_result['usage']['total_tokens'] ) ? (int) $llm_result['usage']['total_tokens'] : 0,
			'content_chars'     => strlen( (string) $accumulated ),
		] );

		// Fallback: if no streaming chunks were emitted but content exists.
		if ( $accumulated === '' && ! empty( $llm_result['content'] ) ) {
			$accumulated = (string) $llm_result['content'];
			$this->emit( 'token', [ 'text' => $accumulated ] );
			$this->emit_streaming_chunk( 'content', $accumulated );
		}

		// Flush any partial tail buffer left from <think> splitter.
		if ( $enable_thinking_split && $buffer_state['tail'] !== '' ) {
			if ( $buffer_state['in_think'] ) {
				$thinking .= $buffer_state['tail'];
				$this->emit( 'thinking', [ 'text' => $buffer_state['tail'] ] );
				$this->emit_streaming_chunk( 'reasoning', $buffer_state['tail'] );
			} else {
				$accumulated .= $buffer_state['tail'];
				$this->emit( 'token', [ 'text' => $buffer_state['tail'] ] );
				$this->emit_streaming_chunk( 'content', $buffer_state['tail'] );
			}
			$buffer_state['tail'] = '';
		}

		// PR-T2 — final safety flush so no buffered chunk is lost across turn end.
		$this->flush_streaming_chunks();

		// Sprint 4.5i — citation validator. Only enforced when KG context was injected.
		$citation_report = null;
		if ( function_exists( 'bizcity_kg_validate_citations' ) && ! empty( $ctx['kg_summary']['sources'] ) ) {
			// Phase 0.6 CITATION V2 (2026-04-28) — server-side normalization. LLMs frequently
			// keep emitting short ordinals ([src:1], [src:2], or just [1]) even when the prompt
			// asks for literal IDs. Rewrite those into [src:{source_id}#p{passage_id}] BEFORE
			// validation/persistence so the FE always receives the canonical form. See
			// PHASE-0.6-CITATION-V2.md §3.
			$accumulated = $this->normalize_citation_markers( $accumulated, $ctx['kg_summary']['sources'] );

			$citation_report = bizcity_kg_validate_citations(
				$accumulated,
				$ctx['kg_summary']['sources'],
				isset( $ctx['kg_summary']['cited_entities'] ) ? $ctx['kg_summary']['cited_entities'] : []
			);
			$this->emit( 'validation', $citation_report );
			// Only log when LLM used an out-of-range citation index (actual wrong reference).
			if ( ! empty( $citation_report['missing'] ) ) {
				error_log( sprintf(
					'[TwinChat] citation out-of-range for session=%s — bad_indexes=%s',
					$session_id,
					implode( ',', $citation_report['missing'] )
				) );
			}
		}

		// Phase 0.6 CITATION V2 (2026-05-05) — defense-in-depth strip pass.
		// Run UNCONDITIONALLY (even when no KG context) so unresolvable markers
		// echoed from history or hallucinated by the LLM never reach the FE.
		$accumulated = $this->strip_unknown_citation_markers(
			$accumulated,
			isset( $ctx['kg_summary']['sources'] ) && is_array( $ctx['kg_summary']['sources'] ) ? $ctx['kg_summary']['sources'] : [],
			isset( $ctx['kg_summary']['cited_entities'] ) && is_array( $ctx['kg_summary']['cited_entities'] ) ? $ctx['kg_summary']['cited_entities'] : []
		);

		// Phase 0.6 Wave B — extract structured citation labels for FE chips + replay.
		$citation_labels = [];
		$citations_meta  = [];
		if ( class_exists( 'BizCity_Twin_Citation_Id_Generator' ) ) {
			$citation_labels = BizCity_Twin_Citation_Id_Generator::extract_from_text( $accumulated );
		}
		if ( ! empty( $citation_labels ) && ! empty( $ctx['kg_summary']['sources'] ) ) {
			$citations_meta = $this->hydrate_citations_meta( $citation_labels, $ctx['kg_summary']['sources'] );
		}

		$total_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		// Sprint 4.4h — close generating + emit explicit done step.
		$this->complete_step( 'generating' );
		$done_id = wp_generate_uuid4();
		$this->emit( 'status', [
			'id'         => $done_id,
			'step'       => 'done',
			'status'     => 'completed',
			'detail'     => sprintf( 'Hoàn tất trong %s', $this->format_duration( $total_ms ) ),
			'durationMs' => $total_ms,
		] );

		// 8. Persist + complete.
		$db = BizCity_TwinChat_Database::instance();
		$db->insert_message( [
			'notebook_id' => $notebook_id,
			'user_id'     => $user_id,
			'session_id'  => $session_id,
			'role'        => 'user',
			'content'     => (string) $args['user_message'],
		] );

		// Register/update the session in webchat_sessions so it survives F5.
		$db->upsert_session( [
			'notebook_id' => $notebook_id,
			'user_id'     => $user_id,
			'session_id'  => $session_id,
			'title'       => mb_substr( (string) $args['user_message'], 0, 80 ),
			'preview'     => mb_substr( (string) $args['user_message'], 0, 255 ),
		] );

		$assistant_id = $db->insert_message( [
			'notebook_id' => $notebook_id,
			'user_id'     => $user_id,
			'session_id'  => $session_id,
			'role'        => 'assistant',
			'content'     => $accumulated,
			'sources'     => $ctx['kg_summary']['sources'],
			'thinking'    => $thinking,
			'kg_entities' => $ctx['kg_summary']['cited_entities'],
			'citations'      => $citation_labels,
			'citations_meta' => $citations_meta,
			'token_count' => isset( $llm_result['usage']['total_tokens'] ) ? (int) $llm_result['usage']['total_tokens'] : 0,
			// Sprint 0.6.16 — split tokens + finish_reason for billing dashboards.
			'prompt_tokens'     => isset( $llm_result['usage']['prompt_tokens'] )     ? (int) $llm_result['usage']['prompt_tokens']     : 0,
			'completion_tokens' => isset( $llm_result['usage']['completion_tokens'] ) ? (int) $llm_result['usage']['completion_tokens'] : 0,
			'finish_reason'     => isset( $llm_result['finish_reason'] )              ? (string) $llm_result['finish_reason']           : '',
		] );

		// Sprint 5.3 fix — emit DB message id so FE can use it as a stable
		// identifier for pin/dedup. Without this the live `msg_xxx` random id
		// would never match the numeric id loaded after F5 → BE dedup query
		// fails and the user can re-pin the same message.
		if ( $assistant_id > 0 ) {
			$sse->emit( 'assistant_persisted', [ 'message_id' => (int) $assistant_id ] );
		}
		// KG sources that were retrieved/cited. Non-blocking; failures are logged.
		try {
			if ( $assistant_id > 0 && class_exists( 'BizCity_KG' ) ) {
				BizCity_KG::xref_intent_retrieval( [
					'cortex'        => 'twinchat',
					'cortex_table'  => $db->table_messages(),
					'cortex_ref_id' => (int) $assistant_id,
					'sources'       => is_array( $ctx['kg_summary']['sources'] ?? null ) ? $ctx['kg_summary']['sources'] : [],
					'cited_labels'  => is_array( $citation_labels ) ? $citation_labels : [],
					'query'         => (string) ( $args['user_message'] ?? '' ),
					'extra_meta'    => [ 'session_id' => $session_id, 'notebook_id' => $notebook_id, 'path' => 'legacy' ],
				] );
			}
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] xref_intent_retrieval (legacy) failed: ' . $e->getMessage() );
		}

		/**
		 * Sprint 4.5g — auto-promote chat message to KG-Hub passage.
		 * Listener: BizCity_KG_Auto_Promoter (gated by length + throttle).
		 *
		 * @param array $message  { id, role, notebook_id, user_id, session_id, content }
		 * @param array $context  { surface: 'twinchat' }
		 */
		do_action( 'bizcity_kg_auto_promote_message', [
			'role'        => 'user',
			'notebook_id' => $notebook_id,
			'user_id'     => $user_id,
			'session_id'  => $session_id,
			'content'     => (string) $args['user_message'],
		], [ 'surface' => 'twinchat' ] );

		do_action( 'bizcity_kg_auto_promote_message', [
			'id'          => $assistant_id,
			'role'        => 'assistant',
			'notebook_id' => $notebook_id,
			'user_id'     => $user_id,
			'session_id'  => $session_id,
			'content'     => $accumulated,
		], [ 'surface' => 'twinchat' ] );

		// Cost log if KG retrieval ran.
		if ( ! empty( $ctx['kg_summary']['passages'] ) && class_exists( 'BizCity_KG_Cost_Guard' ) ) {
			$cg = BizCity_KG_Cost_Guard::instance();
			if ( method_exists( $cg, 'record_usage' ) ) {
				$cg->record_usage( [
					'user_id'       => $user_id,
					'operation'     => defined( 'BizCity_KG_Cost_Guard::OP_EXTRACT' ) ? BizCity_KG_Cost_Guard::OP_EXTRACT : 'extract',
					'notebook_id'   => $notebook_id,
					'input_tokens'  => isset( $llm_result['usage']['prompt_tokens'] ) ? (int) $llm_result['usage']['prompt_tokens'] : 0,
					'output_tokens' => isset( $llm_result['usage']['completion_tokens'] ) ? (int) $llm_result['usage']['completion_tokens'] : 0,
					'meta'          => [ 'surface' => 'twinchat', 'session_id' => $session_id ],
				] );
			}
		}

		$this->emit( 'complete', [
			'message_id'       => $assistant_id,
			'session_id'       => $session_id,
			'answer'           => $accumulated,
			'sources'          => $ctx['kg_summary']['sources'],
			'kg_citations'     => isset( $ctx['kg_summary']['kg_citations'] ) ? $ctx['kg_summary']['kg_citations'] : [],
			'related_entities' => $ctx['kg_summary']['cited_entities'],
			'thinking'         => $thinking,
			'total_ms'         => $total_ms,
			'citation_report'  => $citation_report,
			'citations'        => $citation_labels,
			'citations_meta'   => $citations_meta,
		] );

		/* Wave 2.8b TBR.MEM-N3 (2026-05-23) — Layer 4.7 Memory Writer parity.
		 * Sau khi notebook chat phát final answer, dispatch Memory_Writer tích hợp
		 * Mode 1 (regex "hãy nhớ...") + Mode 2 (LLM extractor có cost-guard).
		 * Emit `memory_write` event vào cả SSE lẫn twin_event_stream cho parity
		 * với master Ask Brain. Failures swallowed. */
		if ( $user_id > 0 && class_exists( 'BizCity_TwinBrain_Memory_Writer' ) ) {
			try {
				$mw_trace_id = is_array( $this->event_stream_turn ) && ! empty( $this->event_stream_turn['trace_id'] )
					? (string) $this->event_stream_turn['trace_id']
					: ( 'twinchat-' . (int) $assistant_id );
				$mw = BizCity_TwinBrain_Memory_Writer::instance()->extract_and_persist(
					$mw_trace_id,
					(string) $args['user_message'],
					(string) $accumulated,
					[
						'user_id'     => (int) $user_id,
						'session_id'  => (string) $session_id,
						'notebook_id' => (int) $notebook_id,
						'surface'     => 'twinchat-notebook',
					]
				);
				$mw_payload = [
					'surface'     => 'twinchat-notebook',
					'notebook_id' => (int) $notebook_id,
					'persisted'   => (int)    ( $mw['persisted']  ?? 0 ),
					'mode'        => (string) ( $mw['mode']       ?? '' ),
					'ops'         => (array)  ( $mw['ops']        ?? [] ),
					'latency_ms'  => (int)    ( $mw['latency_ms'] ?? 0 ),
				];
				$this->emit( 'memory_write', $mw_payload );
				$this->dispatch_turn_event( 'memory_write', $mw_payload );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinChat][memory_write][error] ' . $e->getMessage() );
				}
			}
		}

		// Phase 0.16 Wave 10d.3 — feed TwinChat turn into Intent_Shell shadow
		// diff dashboard so we can compare current TwinChat reply ↔ Intent_Shell
		// candidate reply for the same prompt. Deferred via cron so the SSE
		// response stays unblocked.
		$this->maybe_schedule_intent_shadow_diff( $args, $user_id, $notebook_id, $session_id, (string) $accumulated, (int) $total_ms, [
			'path'        => 'legacy',
			'sources_n'   => is_array( $ctx['kg_summary']['sources'] ?? null ) ? count( $ctx['kg_summary']['sources'] ) : 0,
			'message_id'  => (int) $assistant_id,
		] );
	}

	/**
	 * Sprint 4.4h — emit `status` event marking step as active, with stable id.
	 * FE upserts AgentStep keyed by id (no more findIndex-by-step-name heuristic).
	 */
	private function start_step( $step, $detail ) {
		$this->complete_step( $step ); // close any prior active instance defensively
		$id = wp_generate_uuid4();
		$this->step_ids[ $step ]    = $id;
		$this->step_starts[ $step ] = microtime( true );
		$this->emit( 'status', [
			'id'     => $id,
			'step'   => $step,
			'status' => 'active',
			'detail' => $detail,
		] );
	}

	/**
	 * Mark a step completed and emit duration. No-op if step never started.
	 */
	private function complete_step( $step, array $extra = [] ) {
		if ( empty( $this->step_starts[ $step ] ) ) {
			return;
		}
		$duration = (int) round( ( microtime( true ) - $this->step_starts[ $step ] ) * 1000 );
		$payload  = array_merge( [
			'id'         => $this->step_ids[ $step ],
			'step'       => $step,
			'status'     => 'completed',
			'durationMs' => $duration,
		], $extra );
		$this->emit( 'status', $payload );
		unset( $this->step_starts[ $step ] );
	}

	private function format_duration( $ms ) {
		if ( $ms < 1000 ) {
			return $ms . 'ms';
		}
		if ( $ms < 60000 ) {
			return number_format( $ms / 1000, 1 ) . 's';
		}
		$m = (int) floor( $ms / 60000 );
		$s = (int) floor( ( $ms % 60000 ) / 1000 );
		return $m . 'm ' . $s . 's';
	}

	/**
	 * Phase 0.6 CITATION V2 — Sprint C: auto-repair an answer whose validation
	 * failed. Calls the LLM ONCE with a corrective prompt that lists ONLY the
	 * allowed `[src:N#pM]` ids and asks it to rewrite the answer using only
	 * those ids (or omit the marker if no allowed id supports the claim).
	 *
	 * Behind feature flag `bizcity_twinchat_citation_autorepair` (default OFF).
	 * Hard cap: ONE retry per turn. Failures fall back to the original answer.
	 *
	 * @param string $answer  The current (already normalized) assistant text.
	 * @param array  $sources Allowed sources with source_id + passage_id.
	 * @param array  $report  Validator report containing `missing[]`.
	 * @return string Repaired text, or '' on failure.
	 */
	private function autorepair_citations( string $answer, array $sources, array $report ): string {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) return '';
		if ( $answer === '' ) return '';

		$allowed = [];
		foreach ( $sources as $s ) {
			$sid = (int) ( $s['source_id'] ?? 0 );
			$pid = (int) ( $s['passage_id'] ?? 0 );
			if ( $sid <= 0 || $pid <= 0 ) continue;
			$snippet = (string) ( $s['content_snippet'] ?? '' );
			if ( $snippet !== '' && mb_strlen( $snippet ) > 200 ) {
				$snippet = mb_substr( $snippet, 0, 200 ) . '…';
			}
			$allowed[] = sprintf( '[src:%d#p%d] %s', $sid, $pid, $snippet );
		}
		if ( empty( $allowed ) ) return '';

		$missing = isset( $report['missing'] ) && is_array( $report['missing'] )
			? array_slice( $report['missing'], 0, 8 )
			: [];

		$system = "You are a citation auditor. Rewrite the assistant message below so EVERY citation marker uses ONLY the IDs in 'Allowed citation IDs'.\n"
			. "Rules:\n"
			. "- Keep the prose meaning identical. Do NOT add new claims.\n"
			. "- Replace any invalid marker with the closest allowed marker, OR remove it if no allowed id supports the claim.\n"
			. "- Output ONLY the rewritten message, no preamble.\n\n"
			. "=== Allowed citation IDs ===\n"
			. implode( "\n", $allowed )
			. ( ! empty( $missing ) ? "\n\nInvalid markers found in the message: " . implode( ', ', $missing ) : '' );

		try {
			$resp = BizCity_LLM_Client::instance()->chat(
				[
					[ 'role' => 'system', 'content' => $system ],
					[ 'role' => 'user',   'content' => $answer ],
				],
				[
					'temperature' => 0.1,
					'max_tokens'  => 1500,
					'purpose'     => 'citation_repair',
					'no_fallback' => true, // single retry; don't escalate to fallback model
				]
			);
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] autorepair_citations exception: ' . $e->getMessage() );
			return '';
		}

		if ( ! is_array( $resp ) || empty( $resp['success'] ) ) return '';
		$repaired = trim( (string) ( $resp['message'] ?? '' ) );
		// Sanity: refuse if the repair shrank the answer drastically (>50% loss),
		// indicating the LLM truncated/refused. Better to keep the original.
		if ( $repaired === '' || mb_strlen( $repaired ) < (int) ( mb_strlen( $answer ) * 0.5 ) ) {
			return '';
		}
		return $repaired;
	}

	/**
	 * Phase 0.6 CITATION V2 — rewrite legacy ordinal markers into canonical
	 * `[src:{source_id}#p{passage_id}]` form.
	 *
	 * LLMs often default to short ordinals (`[src:1]`, `[src:2]`, even bare `[1]`)
	 * regardless of how strict the prompt is. The FE renderer is intentionally
	 * strict (no index fallback) to prevent ID-space collisions, so we normalize
	 * here on the server using the ordinal → (source_id, passage_id) map embedded
	 * in `enrich_sources_for_citations()`.
	 *
	 * Rules:
	 *  - `[src:N]` where N matches a known source_id  → kept as-is
	 *  - `[src:N]` where N is an ordinal index        → rewritten
	 *  - `[N]` plain legacy where N is an ordinal     → rewritten
	 *  - Anything else                                → untouched
	 *
	 * @param string $text     The raw assistant text (may contain marker tokens).
	 * @param array  $sources  ctx.kg_summary.sources rows from enrich_sources_for_citations().
	 * @return string Normalized text safe for FE strict lookup.
	 */
	private function normalize_citation_markers( string $text, array $sources ): string {
		if ( $text === '' || empty( $sources ) ) return $text;

		$by_index   = [];
		$valid_sids = [];
		foreach ( $sources as $s ) {
			$idx = isset( $s['index'] ) ? (int) $s['index'] : 0;
			$sid = isset( $s['source_id'] ) ? (int) $s['source_id'] : 0;
			if ( $idx > 0 ) $by_index[ $idx ] = $s;
			if ( $sid > 0 ) $valid_sids[ $sid ] = true;
		}
		if ( empty( $by_index ) ) return $text;

		$rewrite = static function ( $idx ) use ( $by_index ) {
			$row = $by_index[ $idx ] ?? null;
			if ( ! $row ) return null;
			$sid = (int) ( $row['source_id'] ?? 0 );
			$pid = (int) ( $row['passage_id'] ?? 0 );
			if ( $sid <= 0 ) return null;
			return $pid > 0 ? sprintf( '[src:%d#p%d]', $sid, $pid ) : sprintf( '[src:%d]', $sid );
		};

		// 1) `[src:N]` without `#pM` — rewrite only when N is NOT a real source_id.
		$text = preg_replace_callback(
			'/\[src:(\d+)\](?!#p)/',
			static function ( $m ) use ( $rewrite, $valid_sids ) {
				$n = (int) $m[1];
				if ( isset( $valid_sids[ $n ] ) ) return $m[0];
				$repl = $rewrite( $n );
				return $repl ?: $m[0];
			},
			$text
		);

		// 2) Bare `[N]` legacy — convert ONLY when N is a small ordinal in range.
		// Negative look-behind guards against `K1`, `note:1`, `src:1`, `draft:1`, etc.
		$text = preg_replace_callback(
			'/(?<![A-Za-z:])\[(\d{1,3})\](?!\()/',
			static function ( $m ) use ( $rewrite, $by_index ) {
				$n = (int) $m[1];
				if ( ! isset( $by_index[ $n ] ) ) return $m[0];
				$repl = $rewrite( $n );
				return $repl ?: $m[0];
			},
			$text
		);

		return $text;
	}

	/**
	 * Phase 0.6 CITATION V2 (2026-05-05) — strip citation markers that point to
	 * IDs the FE cannot resolve, so the strict renderer never paints `?` chips.
	 *
	 * Two failure modes this guards against:
	 *  1. The current turn has no sources but the LLM emitted `[src:1]` /
	 *     `[KG-3]` / `[3]` (hallucinated or echoed from prior history).
	 *  2. The current turn has sources but the LLM cited an ID outside the
	 *     allowed set (e.g. `[src:484#p5963]` echoed from a previous turn
	 *     while this turn only retrieved sids {1, 12, 87}).
	 *
	 * Implementation: run the SAME regex set as `bizcity_kg_validate_citations()`
	 * and drop any marker whose `(sid[, pid])` is NOT in `$sources`. Bare
	 * legacy `[N]` and `[KG-N]` are dropped when N is out of range.
	 * Trailing/leading whitespace and orphan separators around the dropped
	 * marker are tidied so the prose stays readable.
	 *
	 * Markers preserved: `[rel:N]`, `[note:N]` (cannot be validated here;
	 * downstream UIs handle them).
	 *
	 * `[draft:N]` is dropped by default — the resolver never emits drafts,
	 * so any draft marker the LLM produces is hallucinated. Pass an
	 * `$allowed_drafts` list (passage_ids) to whitelist real drafts when
	 * a future code path injects them.
	 *
	 * @param string $text           The (already normalized) assistant answer.
	 * @param array  $sources        Source rows with `source_id` + `passage_id`.
	 * @param array  $kg_items       Optional cited-entity rows (for `[KG-N]`/`[K1]`/`[ent:N]`).
	 * @param int[]  $allowed_drafts Optional whitelist of valid `[draft:N]` ids.
	 * @return string Cleaned text safe for FE strict lookup.
	 */
	private function strip_unknown_citation_markers( string $text, array $sources, array $kg_items = [], array $allowed_drafts = [] ): string {
		if ( $text === '' ) return $text;

		// Build lookup tables (mirrors validator).
		$valid_src_ids    = [];
		$valid_pid_by_src = [];
		foreach ( $sources as $s ) {
			$sid = (int) ( $s['source_id'] ?? $s['id'] ?? 0 );
			$pid = (int) ( $s['passage_id'] ?? 0 );
			if ( $sid > 0 ) {
				$valid_src_ids[ $sid ] = true;
				if ( $pid > 0 ) $valid_pid_by_src[ $sid ][ $pid ] = true;
			}
		}
		$valid_ent_ids = [];
		foreach ( $kg_items as $k ) {
			$eid = (int) ( $k['id'] ?? $k['entity_id'] ?? 0 );
			if ( $eid > 0 ) $valid_ent_ids[ $eid ] = true;
		}
		$source_count = count( $sources );
		$kg_count     = count( $kg_items );

		// 1) [src:N] / [src:N#pM]
		$text = preg_replace_callback(
			'/\[src:(\d+)(?:#p(\d+))?\]/',
			static function ( $m ) use ( $valid_src_ids, $valid_pid_by_src ) {
				$sid = (int) $m[1];
				$pid = isset( $m[2] ) ? (int) $m[2] : 0;
				if ( empty( $valid_src_ids[ $sid ] ) ) return '';
				// If pid is given AND we know that source's passage list AND pid is not in it → drop.
				if ( $pid > 0 && ! empty( $valid_pid_by_src[ $sid ] ) && empty( $valid_pid_by_src[ $sid ][ $pid ] ) ) {
					return '';
				}
				return $m[0];
			},
			$text
		);

		// 2) [ent:N]
		$text = preg_replace_callback(
			'/\[ent:(\d+)\]/',
			static function ( $m ) use ( $valid_ent_ids ) {
				$eid = (int) $m[1];
				// If we have an explicit ent list, drop unknown ids; otherwise leave alone.
				if ( ! empty( $valid_ent_ids ) && empty( $valid_ent_ids[ $eid ] ) ) return '';
				return $m[0];
			},
			$text
		);

		// 3) [KG-N] / [K1]  (legacy ordinal in kg_items). Whitelist mode: only
		// drop when caller provided a non-empty $kg_items list AND the index is
		// out of range. When no kg list is passed, leave them alone (the FE
		// `KGCitationChip` resolves them against `kgCitations` it receives via
		// SSE — that may differ from $kg_items here).
		$text = preg_replace_callback(
			'/\[(?:KG-|K)(\d{1,4})\]/i',
			static function ( $m ) use ( $kg_count ) {
				if ( $kg_count <= 0 ) return $m[0];
				$n = (int) $m[1];
				if ( $n < 1 || $n > $kg_count ) return '';
				return $m[0];
			},
			$text
		);

		// 4) Bare [N] legacy ordinal (guarded against `K1`, `src:1`, etc. by lookbehind).
		// Whitelist mode: only drop when we have a non-empty source list AND the
		// index is out of range. With no sources, leave bare numerics alone (they
		// might be page numbers, list markers, or footnote refs from prose).
		$text = preg_replace_callback(
			'/(?<![A-Za-z:])\[(\d{1,3})\](?!\()/',
			static function ( $m ) use ( $source_count ) {
				if ( $source_count <= 0 ) return $m[0];
				$n = (int) $m[1];
				if ( $n < 1 || $n > $source_count ) return '';
				return $m[0];
			},
			$text
		);

		// 5) [draft:N] — resolver never emits drafts, so any draft marker is
		// almost certainly hallucinated. Drop unless explicitly whitelisted.
		$allowed_draft_set = [];
		foreach ( $allowed_drafts as $d ) {
			$d = (int) $d;
			if ( $d > 0 ) $allowed_draft_set[ $d ] = true;
		}
		$text = preg_replace_callback(
			'/\[draft:(\d+)\]/',
			static function ( $m ) use ( $allowed_draft_set ) {
				if ( empty( $allowed_draft_set ) ) return '';
				$n = (int) $m[1];
				return isset( $allowed_draft_set[ $n ] ) ? $m[0] : '';
			},
			$text
		);

		// 6) Hallucinated short citation forms cheap LLMs love to emit despite
		// the prompt: `[d1]`, `[d2]`, `[doc1]`, `[doc 1]`, `[ref1]`, `[ref:1]`.
		// FE strict tokenizer doesn't match them so they would render as plain
		// `[d2]` text — visible noise to the user. Always drop.
		$text = preg_replace( '/\[(?:d|doc|ref)\s*:?\s*\d{1,3}\]/i', '', $text );

		// Tidy: collapse "  " left by removed markers; trim spaces before punctuation.
		$text = preg_replace( '/[ \t]{2,}/', ' ', $text );
		$text = preg_replace( '/[ \t]+([,.;:!?\)])/', '$1', $text );
		// Drop empty marker clusters like "[][]" left by mass strip.
		$text = preg_replace( '/(?:\[\])+/', '', $text );

		return $text;
	}

	/**
	 * Phase 0.6 Wave B — hydrate citation labels into structured rows for the FE.
	 *
	 * Each row carries enough info for the rehype `CitationChip` to render a
	 * tooltip + open the SourceDetailDrawer without a follow-up REST call.
	 *
	 * @param string[] $labels   e.g. ['src:187#p9921', 'src:187', 'k1', 'draft:12']
	 * @param array    $sources  ctx.kg_summary.sources
	 * @return array<int, array{label:string, kind:string, source_id?:int, passage_id?:int, title?:string, snippet?:string, url?:string}>
	 */
	private function hydrate_citations_meta( array $labels, array $sources ): array {
		if ( empty( $labels ) ) return [];

		$by_src     = [];
		$by_src_pid = [];
		$by_cite_id = [];
		foreach ( $sources as $s ) {
			$sid = (int) ( $s['source_id'] ?? $s['id'] ?? 0 );
			$pid = (int) ( $s['passage_id'] ?? 0 );
			if ( $sid > 0 && ! isset( $by_src[ $sid ] ) ) $by_src[ $sid ] = $s;
			if ( $sid > 0 && $pid > 0 ) $by_src_pid[ "{$sid}|{$pid}" ] = $s;
			$cid = isset( $s['cite_id'] ) ? strtolower( (string) $s['cite_id'] ) : '';
			if ( $cid !== '' ) $by_cite_id[ $cid ] = $s;
		}

		$out = [];
		foreach ( $labels as $label ) {
			$row = [ 'label' => $label, 'kind' => 'unknown' ];
			if ( preg_match( '/^src:(\d+)(?:#p(\d+))?$/', $label, $m ) ) {
				$sid = (int) $m[1];
				$pid = isset( $m[2] ) ? (int) $m[2] : 0;
				$row['kind']      = 'source';
				$row['source_id'] = $sid;
				if ( $pid > 0 ) $row['passage_id'] = $pid;
				$src = $by_src_pid[ "{$sid}|{$pid}" ] ?? ( $by_src[ $sid ] ?? null );
				if ( $src ) {
					if ( ! empty( $src['title'] ) || ! empty( $src['source_title'] ) ) {
						$row['title'] = (string) ( $src['title'] ?? $src['source_title'] );
					}
					if ( ! empty( $src['snippet'] ) || ! empty( $src['excerpt'] ) ) {
						$row['snippet'] = (string) ( $src['snippet'] ?? $src['excerpt'] );
					}
					if ( ! empty( $src['url'] ) )         $row['url']         = (string) $src['url'];
					if ( ! empty( $src['source_type'] ) ) $row['source_type'] = (string) $src['source_type'];
				}
			} elseif ( preg_match( '/^draft:(\d+)$/', $label, $m ) ) {
				$row['kind']     = 'draft';
				$row['draft_id'] = (int) $m[1];
			} elseif ( preg_match( '/^ent:(\d+)$/', $label, $m ) ) {
				$row['kind']      = 'entity';
				$row['entity_id'] = (int) $m[1];
			} elseif ( preg_match( '/^rel:(\d+)$/', $label, $m ) ) {
				$row['kind']        = 'relation';
				$row['relation_id'] = (int) $m[1];
			} elseif ( preg_match( '/^k(\d+)$/', $label, $m ) ) {
				$row['kind']  = 'kg_index';
				$row['index'] = (int) $m[1];
			} elseif ( isset( $by_cite_id[ $label ] ) ) {
				$src = $by_cite_id[ $label ];
				$row['kind']      = 'legacy';
				$row['cite_id']   = $label;
				$row['source_id'] = (int) ( $src['source_id'] ?? 0 );
				if ( ! empty( $src['title'] ) ) $row['title'] = (string) $src['title'];
			}
			$out[] = $row;
		}
		return $out;
	}

	/* ──────────────────────────────────────────────────────────────────── */

	/**
	 * Compose OpenAI-style chat message array.
	 */
	private function build_messages( array $ctx ) {
		$messages = [];
		$messages[] = [ 'role' => 'system', 'content' => (string) $ctx['system_prompt'] ];

		if ( ! empty( $ctx['history'] ) ) {
			foreach ( $ctx['history'] as $turn ) {
				if ( ! is_array( $turn ) ) {
					continue;
				}
				$role    = isset( $turn['role'] ) ? (string) $turn['role'] : 'user';
				$content = isset( $turn['content'] ) ? (string) $turn['content'] : '';
				if ( $content === '' ) {
					continue;
				}
				if ( ! in_array( $role, [ 'user', 'assistant', 'system' ], true ) ) {
					$role = 'user';
				}
				$messages[] = [ 'role' => $role, 'content' => $content ];
			}
		}

		$messages[] = [ 'role' => 'user', 'content' => (string) $ctx['user_message'] ];
		return $messages;
	}

	/**
	 * Read runtime-registered tool keys from Twin Tool Registry.
	 *
	 * @return string[]
	 */
	private function get_registry_tool_keys() {
		$keys = [];
		if ( class_exists( 'BizCity_Twin_Tool_Registry' ) ) {
			$reg = BizCity_Twin_Tool_Registry::instance();
			if ( method_exists( $reg, 'get_all' ) ) {
				$keys = array_keys( (array) $reg->get_all() );
			}
		}
		$keys = array_values( array_unique( array_filter( array_map( 'strval', $keys ) ) ) );
		return $keys;
	}

	/* ── Sprint 4.7e — Twin Agent delegation path ─────────────────────── */

	/**
	 * Run the request through `BizCity_Twin_Agent::run()`. The agent owns:
	 *   - System prompt (HARD_SYSTEM_PROMPT + tool catalogue)
	 *   - Tool dispatch (search_kg, fetch_url, list_sources, query_entity)
	 *   - Citation IDs `[a3x9]`
	 *   - SSE typed events (status, tool_call, tool_result, sources, token, complete)
	 *   - Citation validator
	 *
	 * TwinChat handler chỉ còn lo: auth, persist message, KG cost log, auto-promote.
	 */
	private function handle_via_twin_agent( array $args, $user_id, $notebook_id, $session_id ) {
		$sse = new BizCity_Twin_SSE_Writer( true );

		// Phase 0.12 Wave B+ — SSE headers are now flushed; safe to dispatch turn_start.
		$this->dispatch_event_stream_turn_start( $args );

		// ── Debug bridge ──────────────────────────────────────────────────────
		// Wire BizCity_Twin_Debug::trace() → SSE `debug` event so the browser
		// console receives every backend trace in real-time.
		// Gate: only active when Twin Debug is ON (wp-config, option, or ?twin_debug=1).
		if ( class_exists( 'BizCity_Twin_Debug' ) && BizCity_Twin_Debug::is_enabled() ) {
			$_debug_sse = $sse;
			add_action(
				'bizcity_intent_pipeline_log',
				static function ( $step, $data, $level, $ms ) use ( $_debug_sse ) {
					// Only forward twin_debug: tagged entries to avoid noise.
					if ( strpos( (string) $step, 'twin_debug:' ) === 0 ) {
						$_debug_sse->emit( 'debug', [
							'step'  => $step,
							'level' => (string) ( $level ?? 'debug' ),
							'ms'    => (float) $ms,
							'data'  => $data,
						] );
					}
				},
				10,
				4
			);
		}

		BizCity_Twin_Debug::trace( 'stream', 'sse_open', [
			'notebook_id' => $notebook_id,
			'user_id'     => $user_id,
			'session_id'  => $session_id,
			'use_kg'      => ! empty( $args['use_kg'] ),
			'msg_len'     => mb_strlen( (string) $args['user_message'] ),
		] );

		// Sprint 4.10.5 — Emit `analyzing` status IMMEDIATELY after the SSE
		// stream is opened, BEFORE auth/cost-guard/DB writes/agent boot. The
		// previous behaviour delayed the first user-visible event until the
		// agent loop hit `class-twin-agent-loop.php:171` which only fires
		// after model resolution, message build, and tool registry init.
		// Result: the user stared at an empty bubble for 0.5–1s after pressing
		// send. This single extra emit guarantees instant visual feedback.
		$sse->emit( 'status', [
			'step'   => 'analyzing',
			'status' => 'active',
			'detail' => 'Đang phân tích yêu cầu...',
		] );

		// ── Wave 0.18.5 + 0.18.5c — Twin Guru context attribution ──────────
		// Resolve the effective Guru for THIS turn with priority:
		//   (1) `target_guru` from composer @-mention   → sticky_source='mention'
		//   (2) sticky user_meta for (user, notebook)   → sticky_source='pinned'
		//   (3) notebook character/provider defaults     → sticky_source='default'
		// Then surface the 3-layer Guru context (Instruction / Knowledge /
		// Personal Artifacts) so the FE timeline can render an expandable
		// "Twin Guru" step BEFORE the LLM call.
		// PHASE-0.17 Step T-1 = guru_lookup (highest-priority orientation step).
		// [2026-07-05 Johnny Chu] HOTFIX — provider-first Twin Guru (no mandatory character).
		$_character_id        = 0;
		$_provider_id         = '';
		$_sticky_source       = 'default';
		$_provider_tool_names = [];
		$_guru_meta           = [
			'character_slug' => '',
			'character_name' => '',
			'avatar_url'     => '',
			'provider_label' => '',
		];

		$_guru_class_ok = class_exists( 'BizCity_KG_Notebook_Service' );
		if ( ! $_guru_class_ok ) {
			error_log( sprintf(
				'[TwinChat][guru_lookup] SKIPPED — required classes missing: BizCity_KG_Notebook_Service=%s notebook_id=%d',
				class_exists( 'BizCity_KG_Notebook_Service' )  ? 'yes' : 'NO',
				$notebook_id
			) );
			$this->dispatch_turn_event( 'decision', [
				'stage'  => 'twin_guru_lookup',
				'kind'   => 'twin_guru_lookup',
				'status' => 'skipped',
				'reason' => 'class_missing',
				'character_id'   => 0,
				'provider_id'    => '',
				'character_name' => '',
			] );
		}
		if ( $notebook_id > 0 && $_guru_class_ok ) {
			$_t_guru_start  = microtime( true );
			$_nb_row = [];

			// (1) @-mention from composer wins.
			if ( is_array( $args['target_guru'] ) ) {
				$_tg_character_id = (int) ( $args['target_guru']['character_id'] ?? 0 );
				$_tg_provider_id  = isset( $args['target_guru']['provider_id'] )
					? sanitize_key( (string) $args['target_guru']['provider_id'] )
					: '';
				if ( $_tg_character_id > 0 || $_tg_provider_id !== '' ) {
					$_character_id = $_tg_character_id;
					$_provider_id  = $_tg_provider_id;
				$_sticky_source = isset( $args['target_guru']['sticky_source'] ) && $args['target_guru']['sticky_source'] === 'pinned'
					? 'pinned'
					: 'mention';
				$_guru_meta = [
					'character_slug' => (string) ( $args['target_guru']['character_slug'] ?? '' ),
					'character_name' => (string) ( $args['target_guru']['character_name'] ?? '' ),
					'avatar_url'     => (string) ( $args['target_guru']['avatar_url'] ?? '' ),
					'provider_label' => (string) ( $args['target_guru']['provider_label'] ?? '' ),
				];
				}
			}

			// (2) sticky user_meta fallback (no @-mention this turn but user pinned earlier).
			if ( $_character_id <= 0 && $_provider_id === '' ) {
				// [2026-06-22 Johnny Chu] R-PERF — route via BizCity_User_Meta_Cache (dynamic key, no meta prime)
				$_sticky_key = 'bizcity_twin_sticky_guru_' . $notebook_id;
				$_sticky = class_exists( 'BizCity_User_Meta_Cache' )
					? BizCity_User_Meta_Cache::get( $user_id, $_sticky_key, array() )
					: get_user_meta( $user_id, 'bizcity_twin_sticky_guru_' . $notebook_id, true );
				if ( is_array( $_sticky ) ) {
					$_sticky_character_id = (int) ( $_sticky['character_id'] ?? 0 );
					$_sticky_provider_id  = isset( $_sticky['provider_id'] ) ? sanitize_key( (string) $_sticky['provider_id'] ) : '';
					if ( $_sticky_character_id > 0 || $_sticky_provider_id !== '' ) {
						$_character_id = $_sticky_character_id;
						$_provider_id  = $_sticky_provider_id;
					$_sticky_source = 'pinned';
					$_guru_meta = [
						'character_slug' => (string) ( $_sticky['character_slug'] ?? '' ),
						'character_name' => (string) ( $_sticky['character_name'] ?? '' ),
						'avatar_url'     => (string) ( $_sticky['avatar_url'] ?? '' ),
						'provider_label' => (string) ( $_sticky['provider_label'] ?? '' ),
					];
					}
				}
			}

			// (3) Notebook default character/provider.
			if ( $_character_id <= 0 || $_provider_id === '' ) {
				$_nb_row = BizCity_KG_Notebook_Service::instance()->get( $notebook_id );
			}

			if ( $_character_id <= 0 ) {
				$_character_id = is_array( $_nb_row ) ? (int) ( $_nb_row['character_id'] ?? 0 ) : 0;
				$_sticky_source = 'default';
			}

			if ( $_provider_id === '' && is_array( $_nb_row ) ) {
				$_nb_settings = $_nb_row['settings'] ?? [];
				if ( is_string( $_nb_settings ) ) {
					$_decoded_settings = json_decode( $_nb_settings, true );
					$_nb_settings = is_array( $_decoded_settings ) ? $_decoded_settings : [];
				}
				if ( is_array( $_nb_settings ) && ! empty( $_nb_settings['twin_guru_provider_id'] ) ) {
					$_provider_id = sanitize_key( (string) $_nb_settings['twin_guru_provider_id'] );
				}
			}

			if ( $_provider_id === '' && $_character_id > 0 && class_exists( 'BizCity_Character' ) ) {
				$_char = BizCity_Character::get( $_character_id );
				if ( $_char && is_array( $_char->settings ) && ! empty( $_char->settings['provider_id'] ) ) {
					$_provider_id = sanitize_key( (string) $_char->settings['provider_id'] );
				}
			}

			// 8.1 Trace — log the resolved character + source so console can follow it.
			$_mention = is_array( $args['target_guru'] )
				&& ( ! empty( $args['target_guru']['character_id'] ) || ! empty( $args['target_guru']['provider_id'] ) );
			error_log( sprintf(
				'[TwinChat][guru_lookup] notebook_id=%d user_id=%d character_id=%d provider_id=%s sticky_source=%s mention=%s',
				$notebook_id, $user_id, $_character_id, $_provider_id, $_sticky_source,
				$_mention ? 'yes' : 'no'
			) );

			if ( $_character_id <= 0 && $_provider_id === '' ) {
				// No character bound at any level — emit an explicit "skipped" decision
				// event so the FE timeline (and console.log) shows WHY there's no Guru.
				$this->dispatch_turn_event( 'decision', [
					'stage'        => 'twin_guru_lookup',
					'kind'         => 'twin_guru_lookup',
					'status'       => 'skipped',
					'reason'       => 'no_guru_bound',
					'notebook_id'  => $notebook_id,
					'character_id' => 0,
					'provider_id'  => '',
					'character_name' => '',
				] );
			}

			if ( $_character_id > 0 && class_exists( 'BizCity_Twin_Guru_Context' ) ) {
				$_guru   = BizCity_Twin_Guru_Context::collect( $_character_id, $notebook_id, $user_id );
				$_l2_n   = count( $_guru['l2_guru_sources'] );
				$_l3_n   = count( $_guru['l3_personal_artifacts'] );
				$_name   = $_guru_meta['character_name'] !== ''
					? $_guru_meta['character_name']
					: ( $_guru['character_name'] !== '' ? $_guru['character_name'] : '#' . $_character_id );
				$_detail = sprintf( 'Twin Guru: %s — L2 %d nguồn · L3 %d artifacts', $_name, $_l2_n, $_l3_n );
				$_latency_ms = (int) round( ( microtime( true ) - $_t_guru_start ) * 1000 );

				// Expose effective guru downstream (system prompt enrichers / tools).
				$args['target_guru'] = [
					'character_id'   => $_character_id,
					'provider_id'    => $_provider_id,
					'character_slug' => $_guru_meta['character_slug'],
					'character_name' => $_name,
					'avatar_url'     => $_guru_meta['avatar_url'],
					'sticky_source'  => $_sticky_source,
				];

				// Wave 0.18.5c — Step T-1 = guru_lookup decision event (BEFORE retrieval).
				// PHASE-0.17 §3.1 — payload.kind='guru_lookup' on the canonical
				// `decision` event taxonomy (no new event type).
				$this->dispatch_turn_event( 'decision', [
					'stage'              => 'twin_guru_lookup',
					'kind'               => 'twin_guru_lookup',
					'character_id'       => $_character_id,
					'character_slug'     => $_guru_meta['character_slug'],
					'character_name'     => $_name,
					'l1_preview'         => (string) ( $_guru['l1_instruction_preview'] ?? '' ),
					'l2_sources_count'   => $_l2_n,
					'l3_artifacts_count' => $_l3_n,
					'sticky_source'      => $_sticky_source,
					'latency_ms'         => $_latency_ms,
				] );

				// Legacy SSE status (for any legacy listener still on `status`).
				$sse->emit( 'status', [
					'step'       => 'guru_layer',
					'status'     => 'completed',
					'detail'     => $_detail,
					'guruLayers' => $_guru,
				] );
				// Canonical Twin Event Stream frame — consumed by eventToStep
				// reducer in the FE (`case 'retrieval'` with scope='twin_guru').
				// PHASE-0.19 Cluster A: previously dispatched as 'knowledge_retrieved'
				// which is NOT in the 15-type taxonomy (R-EVT-2) → rejected by
				// BizCity_Twin_Event_Taxonomy::assert_valid_type(). Re-mapped onto
				// the canonical `retrieval` event with scope='twin_guru' phase='complete'.
				$this->dispatch_turn_event( 'retrieval', [
					'scope'          => 'twin_guru',
					'query'          => (string) ( $_name !== '' ? $_name : '#' . $_character_id ),
					'phase'          => 'complete',
					'source'         => 'twin_guru',
					'character_id'   => $_character_id,
					'character_name' => $_name,
					'sticky_source'  => $_sticky_source,
					'l1_preview'     => $_guru['l1_instruction_preview'],
					'l2_sources'     => $_guru['l2_guru_sources'],
					'l3_artifacts'   => $_guru['l3_personal_artifacts'],
					'detail'         => $_detail,
				] );

				// Phase 0.19.6.3 — L5 model_settings layer (no new event_type, just
				// a `decision` payload the FE accumulator can fold into the Guru card).
				if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
					$_char_row = BizCity_Knowledge_Database::instance()->get_character( $_character_id );
					if ( $_char_row ) {
						$_model_id    = (string) ( $_char_row->model_id ?? '' );
						$_temperature = isset( $_char_row->creativity_level ) ? (float) $_char_row->creativity_level : null;
						$_max_tokens  = isset( $_char_row->max_tokens ) ? (int) $_char_row->max_tokens : null;
						$this->dispatch_turn_event( 'decision', [
							'stage'         => 'twin_guru_layer_resolved',
							'kind'          => 'twin_guru_layer_resolved',
							'layer'         => 'L5',
							'character_id'  => $_character_id,
							'model_id'      => $_model_id !== '' ? $_model_id : 'gpt-4o-mini',
							'temperature'   => $_temperature,
							'max_tokens'    => $_max_tokens,
							'is_default'    => $_model_id === '',
						] );
					}
				}
			}

			if ( $_character_id > 0 && ! class_exists( 'BizCity_Twin_Guru_Context' ) ) {
				$this->dispatch_turn_event( 'decision', [
					'stage'        => 'twin_guru_lookup',
					'kind'         => 'twin_guru_lookup',
					'status'       => 'skipped',
					'reason'       => 'guru_context_class_missing',
					'character_id' => (int) $_character_id,
					'provider_id'  => (string) $_provider_id,
				] );
			}

			if ( $_provider_id !== '' && class_exists( 'BizCity_Persona_Registry' ) ) {
				$_provider = BizCity_Persona_Registry::instance()->get( $_provider_id );
				if ( $_provider ) {
					// [2026-07-05 Johnny Chu] HOTFIX — BizCoach bridge: selecting bizcoach_pro must expose astrology tools directly.
					$_provider_scan_ids = array( (string) $_provider_id );
					if ( (string) $_provider_id === 'bizcoach_pro' ) {
						$_provider_scan_ids = array_merge(
							$_provider_scan_ids,
							array( 'bizcoach_astro', 'bizcoach_astro_western', 'bizcoach_astro_vedic', 'bizcoach_astro_chinese' )
						);
					}
					$_provider_scan_ids = array_values( array_unique( array_filter( array_map( 'strval', $_provider_scan_ids ) ) ) );

					if ( $_guru_meta['provider_label'] === '' ) {
						$_guru_meta['provider_label'] = method_exists( $_provider, 'label' )
							? (string) $_provider->label()
							: (string) $_provider_id;
					}

					foreach ( $_provider_scan_ids as $_scan_id ) {
						$_scan_provider = (string) $_scan_id === (string) $_provider_id
							? $_provider
							: BizCity_Persona_Registry::instance()->get( (string) $_scan_id );
						if ( ! $_scan_provider ) {
							continue;
						}

						$_provider_defs = array();
						try {
							$_provider_defs = (array) $_scan_provider->get_tool_definitions();
						} catch ( \Throwable $e ) {
							$_provider_defs = array();
						}

						foreach ( $_provider_defs as $_tool_name => $_tool_def ) {
							if ( is_array( $_tool_def ) ) {
								$_provider_tool_names[] = isset( $_tool_def['name'] )
									? (string) $_tool_def['name']
									: (string) $_tool_name;
							}
						}
					}

					$this->dispatch_turn_event( 'decision', [
						'stage'          => 'twin_guru_provider_resolved',
						'kind'           => 'twin_guru_provider_resolved',
						'provider_id'    => (string) $_provider_id,
						'provider_label' => (string) $_guru_meta['provider_label'],
						'tools_count'    => count( $_provider_tool_names ),
						'scan_ids'       => $_provider_scan_ids,
						'sticky_source'  => (string) $_sticky_source,
					] );

					$args['target_guru'] = [
						'character_id'   => (int) $_character_id,
						'provider_id'    => (string) $_provider_id,
						'provider_label' => (string) $_guru_meta['provider_label'],
						'character_slug' => (string) $_guru_meta['character_slug'],
						'character_name' => (string) $_guru_meta['character_name'],
						'avatar_url'     => (string) $_guru_meta['avatar_url'],
						'sticky_source'  => (string) $_sticky_source,
					];
				}
			}
		}

		try {
			if ( $user_id <= 0 ) {
				$sse->error( 'Authentication required.', 'auth_required' );
				return;
			}

			// Cost guard (giữ logic cũ).
			if ( class_exists( 'BizCity_KG_Cost_Guard' ) ) {
				$guard = BizCity_KG_Cost_Guard::instance()->can_extract( $user_id, 1 );
				if ( is_wp_error( $guard ) ) {
					$sse->error( $guard->get_error_message(), $guard->get_error_code() );
					return;
				}
			}

			$t0 = microtime( true );

			// Persist user message FIRST (so F5 không mất câu hỏi nếu loop fail).
			$db = BizCity_TwinChat_Database::instance();
			$db->insert_message( [
				'notebook_id' => $notebook_id,
				'user_id'     => $user_id,
				'session_id'  => $session_id,
				'role'        => 'user',
				'content'     => (string) $args['user_message'],
			] );
			$db->upsert_session( [
				'notebook_id' => $notebook_id,
				'user_id'     => $user_id,
				'session_id'  => $session_id,
				'title'       => mb_substr( (string) $args['user_message'], 0, 80 ),
				'preview'     => mb_substr( (string) $args['user_message'], 0, 255 ),
			] );

			// Determine allowed tools (use_kg=false → restrict to non-KG tools).
			$allowed_tools = ! empty( $args['use_kg'] )
				? [ 'search_kg', 'list_sources', 'fetch_url', 'query_entity' ]
				: [ 'fetch_url' ];
			$_registry_keys = $this->get_registry_tool_keys();

			if ( ! empty( $_provider_tool_names ) ) {
				$_provider_tool_names = array_values( array_unique( array_filter( array_map( 'strval', $_provider_tool_names ) ) ) );
				if ( ! empty( $_registry_keys ) ) {
					$_provider_tool_names = array_values( array_intersect( $_provider_tool_names, $_registry_keys ) );
				}
				$allowed_tools = array_values( array_unique( array_merge( $allowed_tools, $_provider_tool_names ) ) );

				$this->dispatch_turn_event( 'decision', [
					'stage'         => 'twin_provider_tools_resolved',
					'kind'          => 'twin_provider_tools_resolved',
					'provider_id'   => (string) $_provider_id,
					'tools'         => $_provider_tool_names,
					'tools_count'   => count( $_provider_tool_names ),
				] );
			}

			// Phase 0.19.6.1+6.2 — Resolve a per-character skill for this turn and
			// extend allowed_tools with the skill's declared tools_json. The Twin
			// Tool Registry already enforces the actual whitelist when execute() runs,
			// so we only union here and intersect against the registry catalogue.
			$_skill_match = null;
			if ( $_character_id > 0 && class_exists( 'BizCity_Skill_Context' ) ) {
				try {
					$_skill_match = BizCity_Skill_Context::instance()->resolve_for_turn(
						(int) $notebook_id,
						(int) $_character_id,
						(int) $user_id,
						(string) $args['user_message']
					);
				} catch ( \Throwable $e ) {
					error_log( '[TwinChat] resolve_for_turn failed: ' . $e->getMessage() );
					$_skill_match = null;
				}
			}

			if ( is_array( $_skill_match ) && ! empty( $_skill_match['tools'] ) ) {
				// Intersect skill tools against the Twin Tool Registry catalogue
				// so frontmatter typos cannot smuggle unknown tool keys downstream.
				$_skill_tools = $_skill_match['tools'];
				if ( ! empty( $_registry_keys ) ) {
					$_skill_tools = array_values( array_intersect( $_skill_tools, $_registry_keys ) );
				}

				$allowed_tools = array_values( array_unique( array_merge( $allowed_tools, $_skill_tools ) ) );

				$this->dispatch_turn_event( 'decision', [
					'stage'         => 'twin_skill_resolved',
					'kind'          => 'twin_skill_resolved',
					'character_id'  => (int) $_character_id,
					'skill_id'      => (int) $_skill_match['skill_id'],
					'skill_key'     => (string) $_skill_match['skill_key'],
					'title'         => (string) $_skill_match['title'],
					'slash_command' => (string) $_skill_match['slash_command'],
					'archetype'     => (string) $_skill_match['archetype'],
					'score'         => (float)  $_skill_match['score'],
					'tools'         => $_skill_tools,
				] );
			} elseif ( $_character_id > 0 ) {
				// No skill matched — emit a skipped decision so the timeline shows WHY.
				$this->dispatch_turn_event( 'decision', [
					'stage'        => 'twin_skill_resolved',
					'kind'         => 'twin_skill_resolved',
					'status'       => 'skipped',
					'reason'       => 'no_match',
					'character_id' => (int) $_character_id,
				] );
			}

			// Phase 0.19.6.3 — Rollup event: complete Guru context (5 layers stitched).
			if ( $_character_id > 0 ) {
				$this->dispatch_turn_event( 'decision', [
					'stage'         => 'twin_guru_context_resolved',
					'kind'          => 'twin_guru_context_resolved',
					'character_id'  => (int) $_character_id,
					'has_skill'     => is_array( $_skill_match ) && ! empty( $_skill_match['skill_id'] ),
					'skill_id'      => is_array( $_skill_match ) ? (int) ( $_skill_match['skill_id'] ?? 0 ) : 0,
					'tools'         => $allowed_tools,
					'tools_count'   => count( $allowed_tools ),
				] );
			}

			$allowed_tools = apply_filters( 'bizcity_twinchat_agent_tools', $allowed_tools, $args, $notebook_id );
			$allowed_tools = array_values( array_unique( array_filter( array_map( 'strval', (array) $allowed_tools ) ) ) );
			if ( ! empty( $_registry_keys ) ) {
				$allowed_tools = array_values( array_intersect( $allowed_tools, $_registry_keys ) );
			}

			BizCity_Twin_Debug::trace( 'stream', 'agent_invoke', [
				'tools'      => $allowed_tools,
				'history_n'  => is_array( $args['history'] ) ? count( $args['history'] ) : 0,
				'use_kg'     => ! empty( $args['use_kg'] ),
			] );

			// Sprint 4.5h — Hình thức C: TwinCore inject.
			// Pre-fetch KG context via Context Resolver and inject as extra_system
			// so the LLM sees authoritative passages directly in the system prompt,
			// independent of tool-calling behaviour. force_search_kg (in Agent loop)
			// still runs to emit SSE sources/kg_citations/kg_highlight events AND to
			// append the passages as TOOL_RESULT messages in the conversation history.
			// When extra_system is already set, force_search_kg becomes belt+suspenders;
			// override that filter to skip the forced tool call only when a non-empty
			// context block was successfully resolved (avoids double retrieval cost).
			$extra_system = '';
			$_kg_skipped_reason = '';
			if ( ! empty( $args['use_kg'] )
				&& $notebook_id > 0
				&& function_exists( 'bizcity_kg_is_main_task' )
				&& bizcity_kg_is_main_task( 'twinchat', 'chat', (string) $args['user_message'] )
				&& class_exists( 'BizCity_Twin_Context_Resolver' )
			) {
				// Signal KG retrieval BEFORE the blocking resolve call so the user
			// sees "Đang tìm kiếm Knowledge Graph..." instead of a frozen
			// "Đang phân tích yêu cầu..." for the entire 3–6 s resolve window.
				$sse->emit( 'status', [
					'step'   => 'kg_retrieving',
					'status' => 'active',
					'detail' => 'Đang tìm kiếm trong Knowledge Graph...',
				] );

				// PR-T2 — emit retrieval(start) on the canonical Twin Event Stream
				// (twin_agent path).
				$this->dispatch_turn_event( 'retrieval', [
					'scope' => 'kg',
					'query' => (string) $args['user_message'],
					'phase' => 'start',
				] );

				BizCity_Twin_Debug::trace( 'stream', 'kg_resolve_start', [
					'notebook_id' => $notebook_id,
					'query_len'   => mb_strlen( (string) $args['user_message'] ),
					'source_ids'  => is_array( $args['source_ids'] ?? null ) ? $args['source_ids'] : [],
				] );

				$_t_kg = microtime( true );

				$resolved = BizCity_Twin_Context_Resolver::resolve(
					[ 'plugin' => 'twinchat', 'scope_id' => $notebook_id, 'scope_type' => 'notebook' ],
					(string) $args['user_message'],
					[
						'use_kg'         => true,
						'source_ids'     => is_array( $args['source_ids'] ?? null ) ? $args['source_ids'] : [],
						// PHASE-0.19 P1 — raise retrieval coverage. Symptoms (2026-05-03):
						// large markdown sources (e.g. natal-chart tables, ~10kB) only
						// returned passages_n=2 with top_k=5 → LLM blind to half the doc.
						// Bumped top_k 5→10 + seeds + hops to widen candidate pool.
						'top_k'          => 10,
						'seed_entities'  => 8,
						'seed_relations' => 24,
						'expand_hops'    => 2,
					]
				);

				$extra_system = (string) ( $resolved['context_block'] ?? '' );

				BizCity_Twin_Debug::trace( 'stream', 'context_resolved', [
					'passages_n'     => count( $resolved['passages'] ?? [] ),
					'kg_citations_n' => count( $resolved['kg_citations'] ?? [] ),
					'sources_n'      => count( $resolved['sources'] ?? [] ),
					'subgraph_nodes' => count( $resolved['subgraph']['nodes'] ?? [] ),
					'subgraph_links' => count( $resolved['subgraph']['links'] ?? [] ),
					'block_len'      => mb_strlen( $extra_system ),
					'injected'       => $extra_system !== '',
				] );

				// 2026-04-30 — also log to PHP error_log so we can quickly spot
				// when resolver returned 0 sources (kg empty) vs > 0 but FE
				// didn't render (delivery bug).
				error_log( sprintf(
					'[TwinChat][resolve] sources=%d kg_cit=%d sg_nodes=%d block_len=%d',
					count( $resolved['sources'] ?? [] ),
					count( $resolved['kg_citations'] ?? [] ),
					count( $resolved['subgraph']['nodes'] ?? [] ),
					mb_strlen( $extra_system )
				) );

				// Complete kg_retrieving step with duration + source count for FE timeline.
				$_src_n = count( $resolved['sources'] ?? [] );
				$_pass_n = count( $resolved['passages'] ?? [] );
				$sse->emit( 'status', [
					'step'       => 'kg_retrieving',
					'status'     => 'completed',
					'detail'     => "Tìm thấy {$_src_n} nguồn · {$_pass_n} đoạn văn",
					'durationMs' => (int) round( ( microtime( true ) - $_t_kg ) * 1000 ),
				] );

				// PR-T2 — emit retrieval(complete) on canonical Twin Event Stream
				// with sources + kg_citations + kg_highlight gathered into ONE envelope.
				$_top_sources_pl = is_array( $resolved['sources'] ?? null ) ? array_values( $resolved['sources'] ) : [];
				$_kg_citations_pl = is_array( $resolved['kg_citations'] ?? null ) ? array_values( $resolved['kg_citations'] ) : [];
				$_sg_nodes_pl = is_array( $resolved['subgraph']['nodes'] ?? null ) ? $resolved['subgraph']['nodes'] : [];
				$_sg_links_pl = is_array( $resolved['subgraph']['links'] ?? null ) ? $resolved['subgraph']['links'] : [];
				$_ent_ids_pl  = array_values( array_filter( array_map( static function ( $n ) { return (int) ( $n['id'] ?? 0 ); }, $_sg_nodes_pl ) ) );
				$_rel_ids_pl  = array_values( array_filter( array_map( static function ( $l ) { return (int) ( $l['id'] ?? 0 ); }, $_sg_links_pl ) ) );
				$this->dispatch_turn_event( 'retrieval', [
					'scope'        => 'kg',
					'query'        => (string) $args['user_message'],
					'phase'        => 'complete',
					'top_sources'  => $_top_sources_pl,
					'kg_citations' => $_kg_citations_pl,
					'kg_highlight' => [
						'entity_ids'   => array_values( array_unique( $_ent_ids_pl ) ),
						'relation_ids' => array_values( array_unique( $_rel_ids_pl ) ),
					],
					'counts'       => [
						'sources'   => count( $_top_sources_pl ),
						'passages'  => $_pass_n,
						'entities'  => count( $_ent_ids_pl ),
						'relations' => count( $_rel_ids_pl ),
					],
				] );

				// 2026-04-30 — ALWAYS emit sources/kg_citations/kg_highlight when
				// the resolver returned data, regardless of whether `$extra_system`
				// (the formatted context block text) is empty. The gate below
				// (disable force_search_kg) stays bound to extra_system so the
				// agent still does its own KG call when context_block is empty.
				// But the FE needs these payloads to populate the graph
				// highlight + Entities tab + suggestion-chip fallback EVEN when
				// the context block formatter dropped passages (edge cases:
				// passages too short, all numeric, etc).
				if ( ! empty( $resolved['sources'] ) ) {
					$sse->emit( 'sources', [ 'sources' => $resolved['sources'] ] );
				}
				if ( ! empty( $resolved['kg_citations'] ) ) {
					$sse->emit( 'kg_citations', [ 'kg_citations' => $resolved['kg_citations'] ] );
				}
				$_sg_nodes_emit = $resolved['subgraph']['nodes'] ?? [];
				$_sg_links_emit = $resolved['subgraph']['links'] ?? [];
				if ( ! empty( $_sg_nodes_emit ) || ! empty( $_sg_links_emit ) ) {
					$_ent_ids_emit = array_values( array_filter( array_map( static function ( $n ) {
						return (int) ( $n['id'] ?? 0 );
					}, $_sg_nodes_emit ) ) );
					$_rel_ids_emit = array_values( array_filter( array_map( static function ( $l ) {
						return (int) ( $l['id'] ?? 0 );
					}, $_sg_links_emit ) ) );
					// 2026-04-30 — also include entity labels so the FE Entities
					// tab can list names without needing a separate /graph fetch.
					$_ent_labels_emit = array_values( array_unique( array_filter( array_map( static function ( $n ) {
						return isset( $n['label'] ) ? (string) $n['label'] : '';
					}, $_sg_nodes_emit ) ) ) );
					if ( ! empty( $_ent_ids_emit ) || ! empty( $_rel_ids_emit ) ) {
						$sse->emit( 'kg_highlight', [
							'entity_ids'    => array_values( array_unique( $_ent_ids_emit ) ),
							'relation_ids'  => array_values( array_unique( $_rel_ids_emit ) ),
							'entity_labels' => $_ent_labels_emit,
						] );
					}
				}

				// When a context block was resolved, disable the agent's own
				// forced search_kg call (retrieval already done above).
				if ( $extra_system !== '' ) {
					add_filter( 'bizcity_twin_agent_force_search_kg', '__return_false', 999 );
				} else {
					$_kg_skipped_reason = 'context_block_empty';
					BizCity_Twin_Debug::trace( 'stream', 'kg_resolve_skip', [
						'reason'         => $_kg_skipped_reason,
						'passages_n'     => count( $resolved['passages'] ?? [] ),
						'sources_emit_n' => count( $resolved['sources'] ?? [] ),
					] );
				}
			} else {
				$_kg_skipped_reason = empty( $args['use_kg'] ) ? 'use_kg_off'
					: ( $notebook_id <= 0 ? 'no_notebook' : 'not_main_task' );
				BizCity_Twin_Debug::trace( 'stream', 'kg_resolve_skip', [ 'reason' => $_kg_skipped_reason ] );
			}

			/* ── Wave 2.8d D6.9e (2026-05-24) — MOVED memory_recall ABOVE history
			 * truncate/scrub block.
			 * Pre-fix: memory_recall ran AFTER REFUSAL ECHO GUARD (block ~1760).
			 * Guard only triggered when `$extra_system !== ''` (KG block).
			 * For Tarot/Guru notebooks with no KG passages → guard SKIPPED →
			 * history kept 5-10 prior "tôi không có khả năng ghi nhớ" turns →
			 * Gemini few-shot echo-bias overrode the freshly-injected memory
			 * block. Now: collect memory FIRST so $extra_system is non-empty
			 * BEFORE scrub, AND extend refusal pattern list with memory-denial
			 * variants so the offending assistant turns get dropped. */
			if ( $user_id > 0 && class_exists( 'BizCity_TwinBrain_Memory_Recall' ) ) {
				// Wave 2.8d D6.9f — timeline layer (mirror guru_lookup pattern).
				// FE eventToStep reducer pushes a 'memory_layer' AgentStep when it
				// sees stage='memory_lookup', then completes it on stage='memory_resolved'.
				$this->dispatch_turn_event( 'decision', [
					'stage'   => 'twin_memory_lookup',
					'kind'    => 'twin_memory_lookup',
					'status'  => 'active',
					'user_id' => (int) $user_id,
				] );
				try {
					$mem_res = BizCity_TwinBrain_Memory_Recall::instance()->collect(
						(int) $user_id,
						(string) $args['user_message'],
						[
							'notebook_id' => (int) $notebook_id,
							'session_id'  => (string) $session_id,
							'surface'     => 'twinchat-notebook-agent',
						]
					);
					$mem_block = (string) ( $mem_res['block'] ?? '' );
					if ( $mem_block !== '' ) {
						$mem_prefix  = "### BEGIN USER MEMORY (HIGH PRIORITY — PERSONAL FACTS ABOUT THE CURRENT USER)\n";
						$mem_prefix .= "The following lines are persistent facts the user has TOLD this assistant in previous conversations.\n";
						$mem_prefix .= "RULES (override any generic 'I cannot remember' default):\n";
						$mem_prefix .= "1. You DO have memory of this user — these facts are it. NEVER say 'tôi không nhớ', 'tôi không có khả năng ghi nhớ', or any equivalent denial.\n";
						$mem_prefix .= "2. When the user asks about themselves (tên, sở thích, công việc, dự án, thói quen…), answer DIRECTLY using these facts.\n";
						$mem_prefix .= "3. When you state a fact drawn from this block, cite it with `[mem:U#<id>]` exactly as listed (the FE renders these as clickable chips).\n";
						$mem_prefix .= "4. If a fact is missing here, you may say so politely, but do NOT deny having memory at all.\n";
						$mem_prefix .= "---\n";
						$mem_prefix .= $mem_block . "\n";
						$mem_prefix .= "### END USER MEMORY\n\n";
						$extra_system = $extra_system !== ''
							? $mem_prefix . $extra_system
							: $mem_prefix;
					}
					$mem_recall_payload = [
						'surface'     => 'twinchat-notebook-agent',
						'notebook_id' => (int) $notebook_id,
						'counts'      => (array) ( $mem_res['counts'] ?? [ 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0 ] ),
						'citations'   => (array) ( $mem_res['citations'] ?? [] ),
						'block_len'   => mb_strlen( $mem_block ),
						'latency_ms'  => (int) ( $mem_res['latency_ms'] ?? 0 ),
						'source'      => (string) ( $mem_res['source'] ?? 'legacy' ),
						'injected'    => $mem_block !== '',
					];
					error_log( sprintf(
						'[TwinChat][agent][memory_recall] user_id=%d notebook=%d source=%s block_len=%d counts=A%d/B%d/C%d/D%d citations=%d latency=%dms extra_system_after=%dB',
						$user_id, $notebook_id,
						$mem_recall_payload['source'],
						$mem_recall_payload['block_len'],
						$mem_recall_payload['counts']['A'] ?? 0,
						$mem_recall_payload['counts']['B'] ?? 0,
						$mem_recall_payload['counts']['C'] ?? 0,
						$mem_recall_payload['counts']['D'] ?? 0,
						count( $mem_recall_payload['citations'] ),
						$mem_recall_payload['latency_ms'],
						mb_strlen( $extra_system )
					) );
					$sse->emit( 'memory_recall', $mem_recall_payload );
					$this->dispatch_turn_event( 'memory_recall', $mem_recall_payload );
					// Wave 2.8d D6.9f — completion event for the timeline layer.
					$this->dispatch_turn_event( 'decision', [
						'stage'           => 'twin_memory_resolved',
						'kind'            => 'twin_memory_lookup',
						'status'          => 'completed',
						'counts'          => $mem_recall_payload['counts'],
						'citations_count' => count( $mem_recall_payload['citations'] ),
						'block_len'       => $mem_recall_payload['block_len'],
						'latency_ms'      => $mem_recall_payload['latency_ms'],
						'source'          => $mem_recall_payload['source'],
						'injected'        => $mem_recall_payload['injected'],
					] );
				} catch ( \Throwable $e ) {
					error_log( '[TwinChat][agent][memory_recall][error] ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
					$this->dispatch_turn_event( 'decision', [
						'stage'  => 'twin_memory_resolved',
						'kind'   => 'twin_memory_lookup',
						'status' => 'skipped',
						'reason' => 'error: ' . $e->getMessage(),
					] );
				}
			} elseif ( $user_id <= 0 ) {
				// Anonymous user — still emit a skipped layer so the timeline
				// shows WHY memory wasn't loaded (helps debugging).
				$this->dispatch_turn_event( 'decision', [
					'stage'  => 'twin_memory_resolved',
					'kind'   => 'twin_memory_lookup',
					'status' => 'skipped',
					'reason' => 'no_user',
				] );
			}

			// === Sprint 4.5j (Wave 10d.4) — History Window Management ===
			// Pre-fix: FE gửi nguyên history (132+ messages, ~71k tokens) → Gemini
			// echo lại câu trả lời cũ thay vì đọc câu hỏi mới + KG block (block 893
			// chars chỉ ~0.3% tổng prompt). Triệu chứng: 3 câu hỏi khác nhau cho 3
			// câu trả lời y hệt với cùng citation [src:200#p144].
			// Truncate cứng 12 turn cuối (24 messages) trừ khi filter override.
			$raw_history = is_array( $args['history'] ) ? $args['history'] : [];
			$max_history = max( 2, (int) apply_filters( 'bizcity_twinchat_history_max_messages', 24, $args, $notebook_id ) );
			if ( count( $raw_history ) > $max_history ) {
				$_orig_n     = count( $raw_history );
				$raw_history = array_slice( $raw_history, -$max_history );
				BizCity_Twin_Debug::trace( 'stream', 'history_truncated', [
					'kept'   => count( $raw_history ),
					'max'    => $max_history,
					'orig_n' => $_orig_n,
					'reason' => 'context-window-guard',
				] );
			}

			// Wave 10d.5b — REFUSAL ECHO GUARD.
			// Root cause discovered from prod log: KG block (1666 chars, 4 passages,
			// 8 citations) WAS injected, system_extra=2045 chars OK, but model
			// returned only 12 completion_tokens = "Tôi chưa thấy thông tin này
			// trong các nguồn của bạn." (52 chars). Why? The 24-message history
			// kept by the truncate-step above was full of *that exact refusal
			// string* from prior Wave 10d.4 turns. Gemini's strong recency bias
			// + few-shot pattern matching → it just copied the prior assistant
			// style instead of reading the new KG block. Even penalties 0.3/0.2
			// can't overcome 12+ identical refusal exemplars in-context.
			//
			// Fix: when we DID inject KG context for this turn, scrub recent
			// assistant turns whose body looks like a refusal/no-info template
			// (or is suspiciously short with no [src: citation). They poison
			// the few-shot. Keep all user turns and any assistant turn that
			// already grounded with [src:N#pM].
			if ( $extra_system !== '' && $raw_history ) {
				$_refusal_patterns = apply_filters(
					'bizcity_twinchat_refusal_patterns',
					[
						'chưa thấy thông tin',
						'chưa có thông tin',
						'không tìm thấy thông tin',
						'không có thông tin',
						'tôi chưa biết',
						'không có nguồn',
						'chưa có nguồn',
					],
					$args
				);
				// Wave 2.8d D6.9g (2026-05-24) — memory-denial patterns ALWAYS
				// drop, kể cả turn có [src:N#pM]/[K...] citation. Lý do: assistant
				// vừa refuse memory vừa trích nguồn KG (vd "tôi không có khả năng
				// ghi nhớ ... nhưng có thể nói về [K2]") — cite-exemption ở
				// patterns thường khiến những turn này lọt qua → Gemini echo bias
				// override luôn USER MEMORY block dù đã inject.
				$_memory_denial_patterns = apply_filters(
					'bizcity_twinchat_memory_denial_patterns',
					[
						'tôi không nhớ',
						'tôi không có khả năng ghi nhớ',
						'tôi không thể nhớ',
						'không có chức năng ghi nhớ',
						'không có khả năng lưu trữ',
						'không lưu trữ thông tin cá nhân',
						'không có trí nhớ',
						'không có khả năng truy cập',
						'không thể truy cập thông tin',
						'không có quyền truy cập',
						'xử lý thông tin dựa trên ngữ cảnh hiện tại',
						'dựa trên ngữ cảnh hiện tại',
						'tôi là một mô hình ngôn ngữ',
						'tôi chỉ là một ai',
						'as an ai language model',
						'as a language model',
						'i don\'t have memory',
						'i cannot remember',
						'i do not have memory',
						'i don\'t have the ability to remember',
					],
					$args
				);
				$_orig_n   = count( $raw_history );
				$_filtered = [];
				$_dropped  = 0;
				$_dropped_memory = 0;
				foreach ( $raw_history as $_msg ) {
					$_role = (string) ( $_msg['role'] ?? '' );
					$_text = (string) ( $_msg['content'] ?? $_msg['text'] ?? '' );
					if ( $_role === 'assistant' ) {
						$_low = mb_strtolower( $_text );
						$_has_cite = ( strpos( $_text, '[src:' ) !== false ) || ( strpos( $_text, '[K' ) !== false );
						$_is_refusal = false;
						// Memory-denial: ALWAYS drop (bypass cite-exemption).
						foreach ( $_memory_denial_patterns as $_pat ) {
							if ( $_pat !== '' && strpos( $_low, mb_strtolower( $_pat ) ) !== false ) {
								$_is_refusal = true;
								$_dropped_memory++;
								break;
							}
						}
						// Context-refusal: chỉ drop khi KHÔNG có cite (giữ legacy).
						if ( ! $_is_refusal && ! $_has_cite ) {
							foreach ( $_refusal_patterns as $_pat ) {
								if ( $_pat !== '' && strpos( $_low, mb_strtolower( $_pat ) ) !== false ) {
									$_is_refusal = true;
									break;
								}
							}
						}
						if ( $_is_refusal ) {
							$_dropped++;
							// Also drop the immediately-preceding user message so we
							// don't leave an orphan user turn that the model might
							// re-answer with the same refusal.
							if ( ! empty( $_filtered ) ) {
								$_last = end( $_filtered );
								if ( ( $_last['role'] ?? '' ) === 'user' ) {
									array_pop( $_filtered );
								}
							}
							continue;
						}
					}
					$_filtered[] = $_msg;
				}
				if ( $_dropped > 0 ) {
					$raw_history = array_values( $_filtered );
					BizCity_Twin_Debug::trace( 'stream', 'history_refusal_scrubbed', [
						'orig_n'         => $_orig_n,
						'kept'           => count( $raw_history ),
						'dropped'        => $_dropped,
						'dropped_memory' => $_dropped_memory,
						'reason'         => 'refusal-echo-guard',
					] );
				}
			}

			// Sprint 4.5j — recency-bias counter-prompt: ép LLM tập trung vào KG block
			// + câu hỏi hiện tại, không echo câu trả lời cũ trong history.
			//
			// Wave 10d.5 — softened: dòng "nếu KG không match thì nói chưa có nguồn"
			// gây refusal cascade khi passages có nội dung mơ hồ (vd "~~Deprioritized~~
			// | Replaced by..."). RULE #0 trong HARD_SYSTEM_PROMPT đã có lệnh này
			// rồi → bỏ duplicate, chỉ giữ chỉ thị anti-echo.
			if ( $extra_system !== '' ) {
				$extra_system .= "\n\n=== ƯU TIÊN TRẢ LỜI ===\n"
					. "1. Đọc kỹ KG block ở trên + câu hỏi hiện tại của người dùng.\n"
					. "2. Tổng hợp thông tin từ MỌI passage có liên quan (kể cả từng phần) — KHÔNG bỏ qua passage chỉ vì nó ngắn hoặc đề cập gián tiếp.\n"
					. "3. KHÔNG sao chép, paraphrase, hoặc echo lại câu trả lời cũ trong lịch sử hội thoại.\n"
					. "4. Cite mọi thông tin bằng nhãn [src:N#pM] đúng như xuất hiện đầu mỗi passage.";
			}

			/* Wave 2.8d D6.9e — memory_recall block MOVED to BEFORE history
			 * scrub (see ~line 1724). Do NOT duplicate it here. */

			$_agent_args = [
				'user_message'   => (string) $args['user_message'],
				'history'        => $raw_history,
				'scope'          => [
					'plugin'    => 'twinchat',
					'scope_id'  => $notebook_id,
					'scope_type'=> 'notebook',
				],
				'tools'          => $allowed_tools,
				'max_iterations' => (int) apply_filters( 'bizcity_twinchat_agent_max_iter', 3, $args ),
				'sse_writer'     => $sse,
				'user_id'        => $user_id,
				'session_id'     => $session_id,
				'temperature'    => 0.4,
				// 2026-05-05 — raised 2048 → 4096. Gemini 2.5-flash with rich KG
				// context (~5kB) was capping answers at 100–200 completion tokens
				// (~400–600 chars), making replies feel cut short. 4096 unblocks
				// detailed analyses (charts, multi-section summaries) without inflating
				// the prompt budget. Filterable via `bizcity_twinchat_max_tokens`.
				'max_tokens'     => (int) apply_filters( 'bizcity_twinchat_max_tokens', 4096, $args ),
				// Sprint 4.5j — anti-echo penalties to combat repetition on long contexts.
				// Gemini 2.5-flash with 70k+ token prompts has strong recency bias and
				// likes to copy the most-recent assistant turn. These penalties weaken
				// that tendency without hurting general fluency. Filterable per request.
				'frequency_penalty' => (float) apply_filters( 'bizcity_twinchat_frequency_penalty', 0.3, $args ),
				'presence_penalty'  => (float) apply_filters( 'bizcity_twinchat_presence_penalty', 0.2, $args ),
				'extra_system'   => $extra_system,
				// Phase 0.6 CITATION V2 — seed agent's aggregated_sources with the
				// resolver's pre-fetched sources. Otherwise the `complete` event
				// emits `sources: []` (forced search_kg is disabled when extra_system
				// is non-empty) and the FE wipes the streamed sources via
				// `payload.sources ?? finalSources` (empty array isn't nullish).
				'extra_sources'  => isset( $resolved['sources'] ) && is_array( $resolved['sources'] )
					? $resolved['sources']
					: [],
				// Sprint 4.5i — tell the agent loop how many [n]-cited passages
				// were injected so it can run the numeric citation validator.
				'numeric_passage_count' => $extra_system !== '' ? count( $resolved['passages'] ?? [] ) : 0,
			];

			BizCity_Twin_Debug::trace( 'stream', 'agent_run_start', [
				'tools'          => $allowed_tools,
				'max_iterations' => $_agent_args['max_iterations'],
				'extra_system'   => $extra_system !== '' ? mb_strlen( $extra_system ) . ' chars' : 'none',
				'numeric_passages' => $_agent_args['numeric_passage_count'],
				'history_n'      => count( $_agent_args['history'] ),
			] );

			// PR-T2 — emit llm_request right before the (potentially long) agent call.
			$this->dispatch_turn_event( 'llm_request', [
				'model'         => apply_filters( 'bizcity_llm_default_model', '', 'agent' ) ?: 'auto',
				'purpose'       => 'agent',
				'temperature'   => isset( $_agent_args['temperature'] ) ? (float) $_agent_args['temperature'] : null,
				'max_tokens'    => isset( $_agent_args['max_tokens'] ) ? (int) $_agent_args['max_tokens'] : null,
				'message_count' => is_array( $_agent_args['history'] ) ? count( $_agent_args['history'] ) : 0,
				'tools'         => $allowed_tools,
				'max_iter'      => (int) $_agent_args['max_iterations'],
			] );

			// PR-T3a — subscribe to twin agent chunk emits so the v2 reducer
			// receives `assistant_streaming_chunk` (text streaming) for THIS path.
			// Legacy `token` SSE event is still emitted by the agent loop in
			// parallel — we strip it in PR-T3b once flag default ON is verified.
			$chunk_listener = function ( $delta, $kind ) {
				if ( ! is_string( $delta ) || '' === $delta ) return;
				$kind = ( $kind === 'reasoning' ) ? 'reasoning' : 'content';
				$this->emit_streaming_chunk( $kind, $delta );
			};
			add_action( 'bizcity_twin_agent_chunk_emitted', $chunk_listener, 10, 2 );

			$result = BizCity_Twin_Agent::run( $_agent_args );

			remove_action( 'bizcity_twin_agent_chunk_emitted', $chunk_listener, 10 );
			// Final flush in case the throttle still has bytes queued.
			$this->flush_streaming_chunks();

			if ( empty( $result['ok'] ) ) {
				BizCity_Twin_Debug::trace( 'stream', 'agent_done', [ 'ok' => false, 'error' => $result['error'] ?? null ] );
				// PR-T2 — mirror agent failure to canonical Event Stream.
				$this->dispatch_turn_event( 'llm_error', [
					'error_msg'  => is_string( $result['error'] ?? null ) ? (string) $result['error'] : 'Twin agent failed.',
					'error_code' => is_string( $result['error_code'] ?? null ) ? (string) $result['error_code'] : 'twin_agent_failed',
					'purpose'    => 'agent',
				] );
				return; // Twin_Agent đã emit error event.
			}

			// PR-T2 — emit llm_response after agent loop returns successfully.
			$this->dispatch_turn_event( 'llm_response', [
				'model_used'        => isset( $result['model'] ) ? (string) $result['model'] : 'auto',
				'finish_reason'     => isset( $result['finish_reason'] ) ? (string) $result['finish_reason'] : '',
				'prompt_tokens'     => isset( $result['usage']['prompt_tokens'] ) ? (int) $result['usage']['prompt_tokens'] : 0,
				'completion_tokens' => isset( $result['usage']['completion_tokens'] ) ? (int) $result['usage']['completion_tokens'] : 0,
				'total_tokens'      => isset( $result['usage']['total_tokens'] ) ? (int) $result['usage']['total_tokens'] : 0,
				'iterations'        => isset( $result['iterations'] ) ? (int) $result['iterations'] : 0,
				'tool_calls'        => is_array( $result['tool_calls'] ?? null ) ? count( $result['tool_calls'] ) : 0,
				'content_chars'     => mb_strlen( (string) ( $result['answer'] ?? '' ) ),
			] );

			// Phase 0.6 CITATION V2 — post-agent normalize + validate + optional repair.
			// Twin_Agent already emitted `complete` via $sse->close() with the raw answer.
			// We now run the same defense-in-depth pass that the legacy stream() path uses:
			//   1) normalize ordinal markers → canonical [src:N#pM]
			//   2) bizcity_kg_validate_citations() to flag missing/invalid IDs
			//   3) (Sprint C) when validator reports `missing` non-empty AND feature flag
			//      `bizcity_twinchat_citation_autorepair` is on, do ONE corrective LLM
			//      pass to rewrite the answer using only Allowed IDs.
			// The repaired answer (if any) replaces $result['answer'] for persistence and
			// is re-emitted via a fresh `complete` event so the FE updates the rendered
			// message (FE handler now does explicit length checks, not `??`).
			$post_sources = is_array( $result['sources'] ?? null ) ? $result['sources'] : [];
			// 2026-05-05 — CRITICAL FIX: re-emit `sources` AFTER the agent loop
			// so FE picks up passages the LLM retrieved via tool_search_kg
			// (e.g. file source 484 with passages 5963-5972). Previously only
			// the pre-LLM resolver sources were emitted (chat-memory only),
			// causing every `[src:484#pXXXX]` marker to render as `?` because
			// FE's strict `${sid}|${pid}` map had no matching row.
			if ( ! empty( $post_sources ) ) {
				$sse->emit( 'sources', [ 'sources' => $post_sources ] );
			}

			// Phase 0.6 CITATION V2 (2026-05-05) — ALWAYS run normalize + strip,
			// even when $post_sources is empty. The unconditional pass kills two
			// classes of unresolvable markers that the FE renders as `?` chips:
			//   • LLM hallucinated `[src:1]` / bare `[1]` on a no-context turn.
			//   • LLM echoed `[src:484#p5963]` from prior assistant history while
			//     the current turn retrieved a disjoint passage set.
			// Empty $post_sources → strip all `[src:*]`/`[N]`/`[KG-N]` markers.
			$normalized = $this->normalize_citation_markers( (string) $result['answer'], $post_sources );

			$report = null;
			if ( function_exists( 'bizcity_kg_validate_citations' ) ) {
				$report = bizcity_kg_validate_citations( $normalized, $post_sources, [] );
				$sse->emit( 'validation', $report );

				$autorepair_on = (bool) apply_filters( 'bizcity_twinchat_citation_autorepair', false, $args, $report );
				if ( $autorepair_on && ! empty( $post_sources ) && ! empty( $report['missing'] ) ) {
					$repaired = $this->autorepair_citations( $normalized, $post_sources, $report );
					if ( $repaired !== '' && $repaired !== $normalized ) {
						$normalized = $this->normalize_citation_markers( $repaired, $post_sources );
						$report     = bizcity_kg_validate_citations( $normalized, $post_sources, [] );
						$sse->emit( 'validation', array_merge( $report, [ 'repaired' => true ] ) );
					}
				}
			}

			// Defense-in-depth: drop markers the FE cannot resolve so the strict
			// renderer never paints `?` chips. Runs regardless of autorepair flag.
			$post_kg_citations = isset( $resolved['kg_citations'] ) && is_array( $resolved['kg_citations'] ) ? $resolved['kg_citations'] : [];
			$cleaned = $this->strip_unknown_citation_markers( $normalized, $post_sources, $post_kg_citations );

			if ( $cleaned !== (string) $result['answer'] ) {
				$result['answer'] = $cleaned;
				// Re-emit complete so FE replaces displayed text with the cleaned version.
				$sse->emit( 'complete', [
					'answer'   => $cleaned,
					'sources'  => $post_sources,
					'citations' => isset( $result['citations'] ) ? $result['citations'] : [],
					'usage'    => isset( $result['usage'] ) ? $result['usage'] : null,
					'model'    => isset( $result['model'] ) ? $result['model'] : '',
					'finish_reason' => isset( $result['finish_reason'] ) ? $result['finish_reason'] : '',
				] );
			}

			$total_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

			BizCity_Twin_Debug::trace( 'stream', 'agent_done', [
				'ok'         => true,
				'iterations' => $result['iterations'] ?? 0,
				'tool_calls' => is_array( $result['tool_calls'] ?? null ) ? count( $result['tool_calls'] ) : 0,
				'sources_n'  => is_array( $result['sources'] ?? null ) ? count( $result['sources'] ) : 0,
				'answer_len' => mb_strlen( (string) $result['answer'] ),
				'total_ms'   => $total_ms,
			] );

			// Sprint 5.2 — extract <suggestions> block from final answer BEFORE persist.
			// Block format defined in HARD_SYSTEM_PROMPT RULE #2 (class-twin-agent-loop.php).
			// We strip it from the persisted content so the user never sees raw XML,
			// and emit the items as a `suggestion_emitted` v2 event so FE renders chips.
			$raw_answer    = (string) $result['answer'];
			$extracted     = $this->extract_suggestions_block( $raw_answer );
			$clean_answer  = $extracted['clean'];
			$suggestions   = $extracted['items']; // array<string> max 4

			// Sprint 5.2 DEBUG — trace why chips may not appear. Logs:
			//   - has_block: did the LLM emit <suggestions>...</suggestions>?
			//   - n_items  : how many parsed (need ≥ 2 to dispatch)
			//   - tail    : last 200 chars of raw answer (block usually at end)
			error_log( sprintf(
				'[TwinChat][suggestions] has_block=%s n_items=%d tail=%s',
				preg_match( '/<suggestions>/i', $raw_answer ) ? 'yes' : 'NO',
				count( $suggestions ),
				json_encode( mb_substr( $raw_answer, max( 0, mb_strlen( $raw_answer ) - 200 ) ) )
			) );

			// Persist assistant message.
			$assistant_id = $db->insert_message( [
				'notebook_id' => $notebook_id,
				'user_id'     => $user_id,
				'session_id'  => $session_id,
				'role'        => 'assistant',
				'content'     => $clean_answer,
				'sources'     => is_array( $result['sources'] ) ? $result['sources'] : [],
				'thinking'    => '',
				'kg_entities' => [],
				'token_count' => isset( $result['usage']['total_tokens'] ) ? (int) $result['usage']['total_tokens'] : 0,
				// Sprint 0.6.16 — Twin_Agent_Loop accumulates usage across multi-turn tool loops.
				'prompt_tokens'     => isset( $result['usage']['prompt_tokens'] )     ? (int) $result['usage']['prompt_tokens']     : 0,
				'completion_tokens' => isset( $result['usage']['completion_tokens'] ) ? (int) $result['usage']['completion_tokens'] : 0,
				'finish_reason'     => isset( $result['finish_reason'] )              ? (string) $result['finish_reason']           : '',
				// 2026-05-05 — promote suggestions to top-level so insert_message()
				// actually persists them (the previous 'metadata' arg was silently
				// dropped — insert_message() ignores unknown keys). Without this,
				// chips were lost on F5 even though they streamed correctly.
				'suggestions' => $suggestions,
				'metadata'    => [
					'twin_agent'  => true,
					'tool_calls'  => $result['tool_calls'],
					'iterations'  => $result['iterations'],
					'model'       => $result['model'],
					'citations'   => $result['citations'],
					'total_ms'    => $total_ms,
					// Sprint 5.2 — persist suggestions for hydration on reload.
					'suggestions' => $suggestions,
				],
			] );

			// Sprint 5.3 fix — emit DB message id (twin_agent path) so FE can use
			// it as a stable identifier for pin/dedup. See note above the legacy
			// path emit for the full rationale.
			if ( $assistant_id > 0 ) {
				$sse->emit( 'assistant_persisted', [ 'message_id' => (int) $assistant_id ] );
			}

			// Phase 0.16 Wave 10d.3 — schedule shadow_compare against Intent_Shell.
			$this->maybe_schedule_intent_shadow_diff( $args, $user_id, $notebook_id, $session_id, (string) $clean_answer, (int) $total_ms, [
				'path'        => 'twin_agent',
				'sources_n'   => is_array( $result['sources'] ?? null ) ? count( $result['sources'] ) : 0,
				'iterations'  => (int) ( $result['iterations'] ?? 0 ),
				'tool_calls'  => is_array( $result['tool_calls'] ?? null ) ? count( $result['tool_calls'] ) : 0,
				'model'       => (string) ( $result['model'] ?? '' ),
				'message_id'  => (int) $assistant_id,
			] );

			// Sprint 5.2 — emit suggestion_emitted v2 event so FE renders chips
			// under THIS message bubble. Use 'pending' as message_id because the
			// FE assigns the bubble id only AFTER stream end; useTwinChatStream
			// buffers items keyed by 'pending' and attaches to final.id below.
			// Replay endpoint sees the same envelope (idempotent by event_uuid).
			// Only emit when we have ≥ 2 items (a single chip looks odd).
			if ( $assistant_id > 0 && count( $suggestions ) >= 2 ) {
				$this->dispatch_turn_event( 'suggestion_emitted', [
					'message_id'    => 'pending',
					'db_message_id' => (int) $assistant_id,
					'items'         => array_values( $suggestions ),
					'reason'        => 'follow_up',
				] );
			}

			// Phase 0.6 Wave A — Brain Reflection: write retrieved/cited xref edges
			// linking this assistant message → KG sources. Non-blocking.
			try {
				if ( $assistant_id > 0 && class_exists( 'BizCity_KG' ) ) {
					BizCity_KG::xref_intent_retrieval( [
						'cortex'        => 'twinchat',
						'cortex_table'  => $db->table_messages(),
						'cortex_ref_id' => (int) $assistant_id,
						'sources'       => is_array( $result['sources'] ?? null ) ? $result['sources'] : [],
						'cited_labels'  => is_array( $result['citations'] ?? null ) ? $result['citations'] : [],
						'query'         => (string) ( $args['user_message'] ?? '' ),
						'extra_meta'    => [
							'session_id'  => $session_id,
							'notebook_id' => $notebook_id,
							'path'        => 'twin_agent',
							'model'       => (string) ( $result['model'] ?? '' ),
							'iterations'  => (int) ( $result['iterations'] ?? 0 ),
						],
					] );
				}
			} catch ( \Throwable $e ) {
				error_log( '[TwinChat] xref_intent_retrieval (agent) failed: ' . $e->getMessage() );
			}

			// Auto-promote (giữ pattern hiện có).
			do_action( 'bizcity_kg_auto_promote_message', [
				'role'        => 'user',
				'notebook_id' => $notebook_id,
				'user_id'     => $user_id,
				'session_id'  => $session_id,
				'content'     => (string) $args['user_message'],
			], [ 'surface' => 'twinchat' ] );
			do_action( 'bizcity_kg_auto_promote_message', [
				'id'          => $assistant_id,
				'role'        => 'assistant',
				'notebook_id' => $notebook_id,
				'user_id'     => $user_id,
				'session_id'  => $session_id,
				'content'     => (string) $result['answer'],
			], [ 'surface' => 'twinchat' ] );
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] twin-agent path error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
			$sse->error( $e->getMessage(), 'twin_agent_exception' );
		}
	}

	/* ── SSE plumbing ──────────────────────────────────────────────────── */

	private function open_sse_stream() {
		// 1) Disable runtime compression *before* sending anything.
		@ini_set( 'zlib.output_compression', '0' );
		@ini_set( 'output_buffering', 'off' );
		@ini_set( 'implicit_flush', '1' );
		ignore_user_abort( true );
		set_time_limit( 0 );

		// 2) Send streaming headers *before* discarding buffers — so the
		//    correct Content-Type is locked in even if PHP emits anything later.
		//    WP REST already sent `Content-Type: application/json`; calling
		//    header() with the same name overrides it as long as headers
		//    haven't been flushed yet (we keep buffers alive until step 3).
		if ( ! headers_sent() ) {
			header_remove( 'Content-Type' );
			header_remove( 'Content-Encoding' );
			header_remove( 'Content-Length' );
			header( 'Content-Type: text/event-stream; charset=UTF-8' );
			// `no-transform` tells Cloudflare/proxies NOT to gzip or buffer.
			header( 'Cache-Control: no-cache, no-store, no-transform, must-revalidate' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );
			header( 'Connection: keep-alive' );
			header( 'X-Accel-Buffering: no' );      // nginx
			header( 'Content-Encoding: none' );     // LiteSpeed/Apache: forbid gzip
			header( 'X-Content-Type-Options: nosniff' );
			if ( function_exists( 'apache_setenv' ) ) {
				@apache_setenv( 'no-gzip', '1' );
			}
		}

		// 3) Drop any output buffers WITHOUT flushing their content
		//    (ob_end_flush would send buffered whitespace → lock headers
		//    before we can override the Content-Type set by WP REST).
		while ( ob_get_level() > 0 ) {
			@ob_end_clean();
		}
		ob_implicit_flush( 1 );

		// 4) Prime the stream + send a 4 KB padding comment to defeat any
		//    proxy buffering that waits for a minimum body before flushing.
		//    2 KB was insufficient on LiteSpeed shared hosting (chunks coalesced
		//    into one burst at end-of-response). Match upstream router-rest pad.
		echo ": sse-open\n" . str_repeat( ' ', 4096 ) . "\n\n";
		@flush();

		$this->heartbeat_ts = microtime( true );

		// Hook into pipeline_log so every blocking KG/rerank/LLM step triggers a
		// heartbeat check. Fires in KG retrieval, reranker, context builder, etc.
		$this->heartbeat_hook = function () {
			$this->maybe_heartbeat();
		};
		add_action( 'bizcity_intent_pipeline_log', $this->heartbeat_hook, 5, 0 );
	}

	private function emit( $event, array $payload ) {
		if ( connection_aborted() ) {
			return;
		}
		$json = wp_json_encode( $payload );
		if ( $json === false ) {
			$json = '{}';
		}
		echo 'event: ' . $event . "\n";
		echo 'data: ' . $json . "\n\n";
		// Defensive double-flush — some hosts (LiteSpeed shared) re-open an
		// output buffer mid-request; ob_flush() empties it, flush() pushes
		// to the wire. Suppress errors when no buffer is active.
		if ( ob_get_level() > 0 ) {
			@ob_flush();
		}
		@flush();
		$this->heartbeat_ts = microtime( true );
	}

	/**
	 * Send an SSE comment line (`: heartbeat`) if no byte has been written
	 * to the wire for ≥ 30 s. Safe to call on every pipeline event or chunk;
	 * the check is cheap (single float subtraction).
	 *
	 * Cloudflare counts idle time from the last byte — a comment line resets
	 * the counter without affecting the EventSource client.
	 */
	private function maybe_heartbeat(): void {
		if ( $this->heartbeat_ts <= 0.0 ) {
			return;
		}
		if ( microtime( true ) - $this->heartbeat_ts >= 30.0 ) {
			echo ": heartbeat\n\n";
			if ( ob_get_level() > 0 ) {
				@ob_flush();
			}
			@flush();
			$this->heartbeat_ts = microtime( true );
		}
	}

	private function close_sse_stream() {
		if ( $this->heartbeat_hook !== null ) {
			remove_action( 'bizcity_intent_pipeline_log', $this->heartbeat_hook, 5 );
			$this->heartbeat_hook = null;
		}
		$this->heartbeat_ts = 0.0;
		echo "event: end\ndata: {}\n\n";
		@flush();
	}

	/* ================================================================
	 * PHASE 0.12 WAVE B+ — TWIN EVENT STREAM BRIDGE (visualization spine)
	 * ================================================================
	 *
	 * Emits canonical Twin events (`turn_start`, `turn_complete`) into
	 * `bizcity_twin_event_stream` AND mirrors every event_v2 dispatched
	 * during this turn out as an additional `event: twin_event` SSE frame.
	 *
	 * The legacy 13-event SSE payload (`status`, `thinking`, `sources`,
	 * `kg_citations`, `kg_highlight`, `token`, `complete`, …) is UNCHANGED.
	 * `twin_event` flows side-by-side until Wave 0.12.B+3 cuts over.
	 *
	 * Failures here MUST never break the legacy SSE pipeline (North Star).
	 */

	private function begin_event_stream_turn( $user_id, $notebook_id, $session_id, array $args ) {
		$this->prepare_event_stream_turn( $user_id, $notebook_id, $session_id, $args );
		$this->dispatch_event_stream_turn_start( $args );
	}

	/**
	 * Reserve trace_id, register the SSE forwarder. Does NOT dispatch turn_start.
	 * Used by both legacy and twin_agent paths so the forwarder is wired up
	 * before any downstream code calls dispatch_v2() on its own.
	 */
	private function prepare_event_stream_turn( $user_id, $notebook_id, $session_id, array $args ) {
		$this->event_stream_turn = null;
		$this->event_stream_forwarder = null;

		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			error_log( '[TwinChat][EventStream] SKIP — BizCity_Twin_Event_Bus class NOT loaded' );
			return;
		}

		try {
			$trace_id   = 'trc_' . bin2hex( random_bytes( 8 ) );
			$started_at = microtime( true );
			error_log( '[TwinChat][EventStream] prepare trace_id=' . $trace_id . ' user=' . (int) $user_id . ' notebook=' . (int) $notebook_id );

			$handler = $this; // PHP 7.4 explicit capture.
			$this->event_stream_forwarder = function ( $event ) use ( $handler ) {
				try {
					$handler->emit_twin_event_frame( $event );
				} catch ( \Throwable $e ) {
					error_log( '[TwinChat] twin_event SSE forwarder failed: ' . $e->getMessage() );
				}
			};
			add_action( 'bizcity_twin_event_v2', $this->event_stream_forwarder, 10, 1 );

			// Mirror every TwinDebug trace as a `decision` event so the FE
			// timeline sees searching / kg_resolve / iter_start / final_answer
			// / agent_done in real-time. Hooked here (not globally) so events
			// are scoped to this turn's trace_id.
			$cur_trace = $trace_id;
			$cur_session = (string) $session_id;
			$cur_user = (int) $user_id;
			$this->event_stream_debug_bridge = function ( $step, $data, $level, $ms )
				use ( $cur_trace, $cur_session, $cur_user ) {
				if ( ! is_string( $step ) || strpos( $step, 'twin_debug:' ) !== 0 ) {
					return;
				}
				if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) ) {
					return;
				}
				try {
					$stage = substr( $step, strlen( 'twin_debug:' ) ); // e.g. stream.kg_resolve_start
					BizCity_Twin_Event_Bus::dispatch_v2(
						'decision',
						[
							'stage'       => $stage,
							'thinking'    => self::summarize_debug_payload( $stage, (array) $data ),
							'duration_ms' => (int) round( (float) $ms ),
							'data'        => is_array( $data ) ? $data : [],
						],
						[
							'trace_id'        => $cur_trace,
							'session_id'      => $cur_session,
							'conversation_id' => $cur_session,
							'user_id'         => $cur_user,
							'event_source'    => 'twinchat',
						]
					);
				} catch ( \Throwable $e ) {
					error_log( '[TwinChat] decision bridge failed (' . $step . '): ' . $e->getMessage() );
				}
			};
			add_action( 'bizcity_intent_pipeline_log', $this->event_stream_debug_bridge, 10, 4 );

			$this->event_stream_turn = [
				'trace_id'         => $trace_id,
				'started_at'       => $started_at,
				'success'          => false,
				'event_uuid'       => '',
				'session_id'       => (string) $session_id,
				'user_id'          => (int) $user_id,
				'turn_start_sent'  => false,
			];
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] prepare_event_stream_turn failed: ' . $e->getMessage() );
			$this->detach_event_stream_forwarder();
			$this->event_stream_turn = null;
		}
	}

	/** Compress a TwinDebug payload into a human-readable Vietnamese line. */
	private static function summarize_debug_payload( string $stage, array $data ): string {
		switch ( $stage ) {
			case 'stream.sse_open':
				return 'Khởi tạo phiên chat';
			case 'stream.agent_invoke':
				return 'Khởi động agent (' . count( $data['tools'] ?? [] ) . ' tools)';
			case 'stream.kg_resolve_start':
				return 'Bắt đầu tìm Knowledge Graph';
			case 'stream.context_resolved':
				return sprintf(
					'Tìm thấy %d nguồn · %d đoạn · %d citations',
					(int) ( $data['passages_n']     ?? 0 ),
					(int) ( $data['kg_citations_n'] ?? 0 ),
					(int) ( $data['block_len']      ?? 0 )
				);
			case 'stream.kg_resolve_skip':
				return 'Bỏ qua KG (' . ( $data['reason'] ?? 'unknown' ) . ')';
			case 'stream.agent_run_start':
				return 'Bắt đầu agent loop (max ' . (int) ( $data['max_iterations'] ?? 0 ) . ' iter)';
			case 'agent.iter_start':
				return 'Iteration ' . (int) ( $data['iter'] ?? 0 );
			case 'agent.final_answer':
				return sprintf( 'Câu trả lời cuối (%d ký tự)', (int) ( $data['answer_len'] ?? 0 ) );
			case 'agent.loop_complete':
				return sprintf(
					'Hoàn tất: %d iter, %d tool calls, %d sources, %d tokens',
					(int) ( $data['iterations']   ?? 0 ),
					(int) ( $data['tool_calls_n'] ?? 0 ),
					(int) ( $data['sources_n']    ?? 0 ),
					(int) ( $data['usage']['total_tokens'] ?? 0 )
				);
			case 'stream.agent_done':
				return sprintf(
					'Agent done · %d ms · %d tool calls',
					(int) ( $data['total_ms']    ?? 0 ),
					(int) ( $data['tool_calls']  ?? 0 )
				);
		}
		return $stage;
	}

	/**
	 * Dispatch the `turn_start` event. MUST be called AFTER the SSE stream
	 * has been opened (headers sent) so the forwarder echo lands inside the
	 * text/event-stream response, not before headers.
	 */
	private function dispatch_event_stream_turn_start( array $args ) {
		if ( ! is_array( $this->event_stream_turn ) ) {
			return;
		}
		if ( ! empty( $this->event_stream_turn['turn_start_sent'] ) ) {
			return;
		}
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			return;
		}

		try {
			error_log( '[TwinChat][EventStream] dispatch turn_start trace_id=' . $this->event_stream_turn['trace_id'] );
			$event_uuid = BizCity_Twin_Event_Bus::dispatch_v2(
				'turn_start',
				[
					'mode'            => 'twinchat',
					'notebook_id'     => isset( $args['notebook_id'] ) ? (int) $args['notebook_id'] : 0,
					'use_kg'          => (bool) ( $args['use_kg'] ?? false ),
					'enable_thinking' => (bool) ( $args['enable_thinking'] ?? false ),
					'source_count'    => is_array( $args['source_ids'] ?? null ) ? count( $args['source_ids'] ) : 0,
					'history_turns'   => is_array( $args['history']    ?? null ) ? count( $args['history'] )    : 0,
				],
				[
					'trace_id'        => $this->event_stream_turn['trace_id'],
					'session_id'      => $this->event_stream_turn['session_id'],
					'conversation_id' => $this->event_stream_turn['session_id'],
					'user_id'         => $this->event_stream_turn['user_id'],
					'event_source'    => 'twinchat',
				]
			);
			$this->event_stream_turn['event_uuid']      = (string) $event_uuid;
			$this->event_stream_turn['turn_start_sent'] = true;
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] dispatch_event_stream_turn_start failed: ' . $e->getMessage() );
		}
	}

	private function end_event_stream_turn() {
		if ( ! is_array( $this->event_stream_turn ) ) {
			$this->detach_event_stream_forwarder();
			return;
		}

		try {
			if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
				$duration_ms = (int) round( ( microtime( true ) - $this->event_stream_turn['started_at'] ) * 1000 );
				$payload = [
					'success'     => (bool) $this->event_stream_turn['success'],
					'duration_ms' => $duration_ms,
				];
				if ( ! empty( $this->event_stream_turn['error_msg'] ) ) {
					$payload['error_msg']   = $this->event_stream_turn['error_msg'];
					$payload['error_where'] = $this->event_stream_turn['error_where'] ?? '';
				}
				BizCity_Twin_Event_Bus::dispatch_v2(
					'turn_complete',
					$payload,
					[
						'trace_id'           => $this->event_stream_turn['trace_id'],
						'session_id'         => $this->event_stream_turn['session_id'],
						'conversation_id'    => $this->event_stream_turn['session_id'],
						'user_id'            => $this->event_stream_turn['user_id'],
						'event_source'       => 'twinchat',
						'parent_event_uuid'  => $this->event_stream_turn['event_uuid'],
					]
				);
			}
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] end_event_stream_turn failed: ' . $e->getMessage() );
		} finally {
			$this->detach_event_stream_forwarder();
			$this->event_stream_turn = null;
		}
	}

	private function detach_event_stream_forwarder() {
		if ( $this->event_stream_forwarder !== null ) {
			remove_action( 'bizcity_twin_event_v2', $this->event_stream_forwarder, 10 );
			$this->event_stream_forwarder = null;
		}
		if ( $this->event_stream_debug_bridge !== null ) {
			remove_action( 'bizcity_intent_pipeline_log', $this->event_stream_debug_bridge, 10 );
			$this->event_stream_debug_bridge = null;
		}
	}

	/**
	 * Sprint 5.2 — extract `<suggestions>` block from a final assistant answer.
	 *
	 * Format (defined by HARD_SYSTEM_PROMPT RULE #2):
	 *   <suggestions>
	 *   - First follow-up question
	 *   - Second follow-up question
	 *   </suggestions>
	 *
	 * Returns [ 'clean' => string, 'items' => string[] ].
	 * Failure-tolerant: malformed / missing block → returns original text + [].
	 */
	private function extract_suggestions_block( string $text ): array {
		if ( $text === '' ) {
			return [ 'clean' => $text, 'items' => [] ];
		}
		if ( ! preg_match( '/<suggestions>([\s\S]*?)<\/suggestions>/i', $text, $m ) ) {
			return [ 'clean' => $text, 'items' => [] ];
		}
		$body  = trim( (string) $m[1] );
		$lines = preg_split( '/\r?\n/', $body );
		$items = [];
		if ( is_array( $lines ) ) {
			foreach ( $lines as $line ) {
				$line = trim( (string) $line );
				if ( $line === '' ) {
					continue;
				}
				// Strip leading "- ", "* ", "• ", "1.", "1)" etc.
				$line = preg_replace( '/^\s*([-*•]|\d+[.)])\s+/u', '', $line );
				$line = trim( (string) $line );
				if ( $line === '' ) {
					continue;
				}
				if ( mb_strlen( $line ) > 200 ) {
					continue; // sanity cap
				}
				$items[] = $line;
				if ( count( $items ) >= 4 ) {
					break;
				}
			}
		}
		$clean = preg_replace( '/<suggestions>[\s\S]*?<\/suggestions>/i', '', $text );
		$clean = is_string( $clean ) ? rtrim( $clean ) : $text;
		return [ 'clean' => $clean, 'items' => $items ];
	}

	/**
	 * PR-T2 — dispatch a Twin Event Stream envelope scoped to the current turn.
	 * Auto-injects trace_id / session_id / user_id / event_source from the
	 * active turn so callsites only pass type + payload. Failure-tolerant:
	 * never let event-stream issues break the legacy SSE pipeline.
	 */
	private function dispatch_turn_event( string $type, array $payload, array $extras = [] ): void {
		if ( ! is_array( $this->event_stream_turn ) ) {
			return;
		}
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			return;
		}
		try {
			$opts = array_merge(
				[
					'trace_id'           => $this->event_stream_turn['trace_id'],
					'session_id'         => $this->event_stream_turn['session_id'],
					'conversation_id'    => $this->event_stream_turn['session_id'],
					'user_id'            => $this->event_stream_turn['user_id'],
					'event_source'       => 'twinchat',
					'parent_event_uuid'  => ! empty( $this->event_stream_turn['event_uuid'] )
						? $this->event_stream_turn['event_uuid']
						: null,
				],
				$extras
			);
			BizCity_Twin_Event_Bus::dispatch_v2( $type, $payload, $opts );
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] dispatch_turn_event(' . $type . ') failed: ' . $e->getMessage() );
		}
	}

	/**
	 * PR-T2 — buffer & throttle assistant_streaming_chunk envelopes.
	 *
	 * Each LLM token would otherwise emit a new event + persist a row. We
	 * coalesce by chunk_kind and flush when EITHER cumulative length exceeds
	 * ~80 chars OR ~500 ms have passed since the last flush. The legacy
	 * `token`/`thinking` SSE frames still go out per token (untouched) so
	 * existing FE rendering speed is unchanged — the Twin Event Stream view
	 * just gets coarser-grained envelopes suitable for the timeline.
	 */
	private function emit_streaming_chunk( string $kind, string $delta ): void {
		if ( $delta === '' ) {
			return;
		}
		$key = 'pending_' . ( $kind === 'reasoning' ? 'reasoning' : 'content' );
		$this->chunk_emit_state[ $key ] .= $delta;

		$now           = microtime( true );
		$elapsed       = $now - (float) $this->chunk_emit_state['last_ts'];
		$pending_total = strlen( (string) $this->chunk_emit_state['pending_content'] )
			+ strlen( (string) $this->chunk_emit_state['pending_reasoning'] );

		if ( $elapsed < 0.5 && $pending_total < 80 ) {
			return;
		}
		$this->flush_streaming_chunks();
	}

	/** Flush any pending coalesced chunks (one envelope per chunk_kind). */
	private function flush_streaming_chunks(): void {
		foreach ( [ 'content', 'reasoning' ] as $k ) {
			$key = 'pending_' . $k;
			$buf = isset( $this->chunk_emit_state[ $key ] ) ? (string) $this->chunk_emit_state[ $key ] : '';
			if ( $buf === '' ) {
				continue;
			}
			$this->dispatch_turn_event( 'assistant_streaming_chunk', [
				'delta'      => $buf,
				'chunk_kind' => $k,
			] );
			$this->chunk_emit_state[ $key ] = '';
		}
		$this->chunk_emit_state['last_ts'] = microtime( true );
	}

	/**
	 * Public so the closure registered in begin_event_stream_turn() can call
	 * it across PHP 7.4 closure-binding rules. NOT a public API surface.
	 *
	 * @internal
	 */
	public function emit_twin_event_frame( array $event ) {
		// Filter to events from this turn only — avoid cross-talk if multiple
		// SSE responses interleave (theoretical; WP usually serializes).
		if ( ! is_array( $this->event_stream_turn ) ) {
			return;
		}
		$evt_trace = (string) ( $event['trace_id'] ?? '' );
		$cur_trace = (string) ( $this->event_stream_turn['trace_id'] ?? '' );
		if ( $evt_trace !== $cur_trace ) {
			error_log( '[TwinChat][EventStream] SKIP twin_event — trace mismatch: evt=' . $evt_trace . ' cur=' . $cur_trace . ' type=' . ( $event['event_type'] ?? '?' ) );
			return;
		}
		error_log( '[TwinChat][EventStream] >>> SSE twin_event type=' . ( $event['event_type'] ?? '?' ) . ' source=' . ( $event['event_source'] ?? '?' ) );
		$this->emit( 'twin_event', $event );
	}

	/* ------------------------------------------------------------------
	 * Phase 0.16 Wave 10d.3 — Intent Shell shadow diff bridge
	 *
	 * The shadow_diff dashboard at /wp-admin/admin.php?page=bizcity-intent-shadow-diff
	 * historically only saw rows from the legacy `BizCity_Intent_Engine::process()`
	 * path (webchat / chat-gateway). TwinChat's SSE pipeline calls
	 * `BizCity_LLM_Client::chat_stream()` directly and bypassed Intent_Engine,
	 * so its turns never appeared in the dashboard.
	 *
	 * We now schedule a single-fire cron AFTER the SSE turn completes that:
	 *   1. Re-runs the prompt through `BizCity_Intent_Shell::handle()` (the
	 *      candidate pipeline).
	 *   2. Logs a `bizcity_intent_shadow_diff` row comparing TwinChat's
	 *      already-streamed reply (treated as "legacy" baseline) against the
	 *      Intent_Shell candidate reply.
	 *
	 * Gate: only runs when `BizCity_Intent_Shell_Config::is_shadow_enabled()`.
	 * Re-running an LLM call per turn is non-trivial cost, so admins must
	 * opt-in via the Shadow Mode toggle in the dashboard.
	 * ------------------------------------------------------------------ */
	private function maybe_schedule_intent_shadow_diff( array $args, $user_id, $notebook_id, $session_id, string $reply, int $total_ms, array $extra_meta = [] ) {
		if ( ! class_exists( 'BizCity_Intent_Shell_Config' )
			|| ! class_exists( 'BizCity_Intent_Shell' ) ) {
			return;
		}
		if ( ! BizCity_Intent_Shell_Config::is_shadow_enabled() ) {
			return;
		}
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}
		$message = (string) ( $args['user_message'] ?? '' );
		if ( $message === '' ) {
			return;
		}

		$params = [
			'user_id'         => $user_id,
			'message'         => $message,
			'channel'         => 'twinchat',
			'session_id'      => (string) $session_id,
			'conversation_id' => (string) $session_id,
			'context_overrides' => [
				'notebook_id' => (int) $notebook_id,
			],
		];

		$legacy_response = [
			'reply'  => $reply,
			'action' => 'reply',
			'status' => 'OK',
			'meta'   => array_merge(
				[ 'source' => 'twinchat', 'notebook_id' => (int) $notebook_id ],
				$extra_meta
			),
		];

		try {
			wp_schedule_single_event(
				time() + 1,
				'bizcity_twinchat_intent_shadow_compare',
				[ $params, $legacy_response, $total_ms ]
			);
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat] schedule intent_shadow_compare failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Cron handler — runs Intent_Shell::shadow_compare() out-of-band.
	 * Registered at file tail via add_action().
	 */
	public static function cron_intent_shadow_compare( $params, $legacy_response, $legacy_ms ) {
		if ( ! class_exists( 'BizCity_Intent_Shell' ) ) {
			return;
		}
		try {
			BizCity_Intent_Shell::instance()->shadow_compare(
				is_array( $params ) ? $params : [],
				is_array( $legacy_response ) ? $legacy_response : [],
				(int) $legacy_ms
			);
		} catch ( \Throwable $e ) {
			error_log( '[TwinChat][cron_intent_shadow_compare] ' . $e->getMessage() );
		}
	}
}

// Phase 0.16 Wave 10d.3 — register cron handler that bridges TwinChat turns
// into the Intent Shell shadow diff dashboard.
add_action(
	'bizcity_twinchat_intent_shadow_compare',
	[ 'BizCity_TwinChat_Stream_Handler', 'cron_intent_shadow_compare' ],
	10,
	3
);
