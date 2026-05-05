<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Runtime
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Phase 0.13 — Task 1.13.6
 * BizCity_Twin_Runner — MVP single-turn agent runner.
 *
 * Vòng 1 scope:
 *   • No tool execution loop (tool specs are passed to LLM but not executed)
 *   • No HIL gate
 *   • No SSE streaming
 *   • One LLM call → RunState{status:completed}
 *
 * Vòng 2: add tool execution loop + HIL pause/resume.
 * Vòng 3: add handoff dispatch.
 *
 * @since 1.13.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Runner' ) ) return;

/**
 * BizCity Twin Runner (MVP)
 *
 * Orchestrates a single agent turn:
 *   1. Resolve agent from registry
 *   2. Build message list (session history + new input)
 *   3. Call LLM via BizCity_LLM_Client
 *   4. Append assistant reply to session + RunState
 *   5. Persist RunState to DB
 *   6. Return completed RunState
 */
final class BizCity_Twin_Runner {

	/** @var BizCity_Twin_Agent_Registry */
	private $agents;

	/** @var BizCity_Twin_Session */
	private $session;

	/**
	 * @param BizCity_Twin_Agent_Registry $agents
	 * @param BizCity_Twin_Session        $session
	 */
	public function __construct(
		BizCity_Twin_Agent_Registry $agents,
		BizCity_Twin_Session $session
	) {
		$this->agents  = $agents;
		$this->session = $session;
	}

	/* ================================================================
	 *  Public API
	 * ================================================================ */

	const MAX_TOOL_ITERATIONS = 5;

	/** @var array<int,array{kind:string,name:string,ms:int}> Sprint 5 — per-step perf log. */
	private $perf_log = [];

	/** Sprint 5 — expose perf log for Intent_Shell observability. */
	public function get_perf_log(): array {
		return $this->perf_log;
	}

	private function perf_record( string $kind, string $name, float $started ): void {
		$ms = (int) round( ( microtime( true ) - $started ) * 1000 );
		$this->perf_log[] = [ 'kind' => $kind, 'name' => $name, 'ms' => $ms ];
		error_log( sprintf( '[Twin_Runner perf] %s=%dms name=%s', $kind, $ms, $name ) );
	}

	/**
	 * Execute one agent turn (or resume a paused run).
	 *
	 * Vòng 2 — implements:
	 *   • Prompt-based tool calling loop (LLM outputs JSON tool call → runner executes)
	 *   • HIL pause: tool with needs_approval=true → status=paused_hil
	 *   • Resume: when run_id + decisions provided in $ctx, apply them then continue loop
	 *
	 * @param string       $agent_name  Agent identifier.
	 * @param array|string $input       Single message string or messages array.
	 * @param array        $ctx         Context. Special keys:
	 *                                    - run_id (string): resume an existing paused run
	 *                                    - decisions (array): [call_id => ['decision'=>..,'reason'=>..]]
	 * @return BizCity_Twin_RunState
	 */
	public function run( string $agent_name, $input, array $ctx = [] ): BizCity_Twin_RunState {
		// ── Resolve agent ─────────────────────────────────────────────
		$agent = $this->agents->get( $agent_name );
		if ( $agent === null ) {
			return $this->make_failed_state( $agent_name, $ctx, "Agent '{$agent_name}' not found in registry." );
		}

		// ── Resume or create RunState ────────────────────────────────
		$resume_run_id = isset( $ctx['run_id'] ) ? (string) $ctx['run_id'] : '';
		$decisions     = isset( $ctx['decisions'] ) && is_array( $ctx['decisions'] ) ? $ctx['decisions'] : [];

		if ( $resume_run_id !== '' ) {
			$state = BizCity_Twin_RunState::load( $resume_run_id );
			if ( $state === null ) {
				global $wpdb;
				$tbl   = $wpdb->prefix . 'bizcity_trace_runs';
				$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tbl}`" );
				$exact = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$tbl}` WHERE run_id=%s", $resume_run_id ) );
				$last  = $wpdb->get_var( "SELECT run_id FROM `{$tbl}` ORDER BY id DESC LIMIT 1" );
				return $this->make_failed_state(
					$agent_name,
					$ctx,
					sprintf(
						"Run '%s' not found for resume. [diag] table=%s rows=%d exact=%d last=%s wpdb_err=%s",
						$resume_run_id,
						$tbl,
						$count,
						$exact,
						$last ?: '(none)',
						$wpdb->last_error ?: '(none)'
					)
				);
			}
			// Apply decisions: execute approved tools, append rejection messages.
			$applied = $this->apply_decisions( $state, $agent, $decisions, $ctx );
			if ( ! $applied ) {
				// No matching pending calls were resolved — return state as-is.
				$state->persist();
				return $state;
			}
		} else {
			$state = BizCity_Twin_RunState::create( $agent_name, $ctx );
			$new_messages = $this->normalize_input( $input );
			$this->session->add_items( $new_messages );
			$state->messages = $this->session->get_items();
		}

		// Emit run lifecycle event (resume vs fresh start).
		if ( class_exists( 'BizCity_TwinShell_Event_Bus' ) ) {
			BizCity_TwinShell_Event_Bus::emit(
				$state->run_id,
				$resume_run_id !== '' ? BizCity_TwinShell_Event_Bus::TYPE_RESUMED : BizCity_TwinShell_Event_Bus::TYPE_RUN_START,
				[
					'agent_name'      => $agent_name,
					'conversation_id' => $state->conversation_id,
					'message_count'   => count( $state->messages ),
				]
			);
		}

		// ── LLM client check ─────────────────────────────────────────
		$llm_client = BizCity_LLM_Client::instance();
		if ( ! $llm_client->is_ready() ) {
			return $this->finalize_failed( $state, 'LLM client not configured (missing API key).' );
		}

		// ── Tool execution loop ──────────────────────────────────────
		$state->status = 'running';
		// Vòng 3 / Sprint 6 — propagate the active run_id into ctx so tools
		// (notably Agent::as_tool() callbacks that spawn sub-runs) can emit
		// `subagent_started` / `subagent_completed` events into THIS run's
		// stream. Without this, a fresh-start run has no run_id in ctx and
		// the canvas-activation bridge silently no-ops.
		$ctx['run_id']  = $state->run_id;
		$tool_specs    = $agent->get_tools_spec( $ctx );
		$tool_map      = [];
		foreach ( $agent->get_enabled_tools( $ctx ) as $t ) {
			$tool_map[ $t->name ] = $t;
		}

		$system_prompt = $this->build_system_prompt( $agent, $tool_specs );

		for ( $i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++ ) {
			$llm_messages = array_merge(
				[ [ 'role' => 'system', 'content' => $system_prompt ] ],
				$state->messages
			);

			$llm_options = [ 'purpose' => 'chat' ];
			if ( ! empty( $agent->model ) ) {
				$llm_options['model'] = $agent->model;
			}

			$llm_started = microtime( true );
			$result      = $llm_client->chat( $llm_messages, $llm_options );
			$this->perf_record( 'llm_call', $agent_name . '#' . ( $i + 1 ), $llm_started );

			if ( empty( $result['success'] ) ) {
				return $this->finalize_failed( $state, $result['error'] ?? 'LLM call failed.' );
			}

			$reply = (string) ( $result['message'] ?? '' );

			// Try to parse a tool call out of the LLM reply
			$tool_call = $this->parse_tool_call( $reply );

			if ( $tool_call === null || ! isset( $tool_map[ $tool_call['tool'] ] ) ) {
				// Final answer — finish.
				$assistant_msg = [ 'role' => 'assistant', 'content' => $reply ];
				$this->session->add_items( [ $assistant_msg ] );
				$state->messages[]   = $assistant_msg;
				$state->status       = 'completed';
				$state->final_output = $reply;
				if ( class_exists( 'BizCity_TwinShell_Event_Bus' ) ) {
					BizCity_TwinShell_Event_Bus::emit(
						$state->run_id,
						BizCity_TwinShell_Event_Bus::TYPE_FINAL,
						[ 'final_output' => $reply ]
					);
				}
				$state->persist();
				return $state;
			}

			// We have a recognized tool call.
			$tool_name = $tool_call['tool'];
			$args      = isset( $tool_call['args'] ) && is_array( $tool_call['args'] ) ? $tool_call['args'] : [];
			$tool      = $tool_map[ $tool_name ];
			$call_id   = 'call_' . substr( md5( $tool_name . wp_json_encode( $args ) . microtime( true ) ), 0, 12 );

			// Record the assistant's tool-call turn for context replay.
			$state->messages[] = [
				'role'    => 'assistant',
				'content' => wp_json_encode( [ 'tool' => $tool_name, 'args' => $args, 'call_id' => $call_id ] ),
			];

			// HIL gate
			if ( $tool->needs_approval( $args, $ctx ) ) {
				$interruption = [
					'call_id'   => $call_id,
					'tool_name' => $tool_name,
					'args'      => $args,
					'reason'    => 'requires_approval',
				];
				$state->pending_tool_calls[] = $interruption;
				$state->interruptions[]      = $interruption;
				$state->status               = 'paused_hil';
				if ( class_exists( 'BizCity_TwinShell_Event_Bus' ) ) {
					BizCity_TwinShell_Event_Bus::emit(
						$state->run_id,
						BizCity_TwinShell_Event_Bus::TYPE_INTERRUPT,
						$interruption
					);
				}
				$state->persist();
				return $state;
			}

			// Auto-execute
			if ( class_exists( 'BizCity_TwinShell_Event_Bus' ) ) {
				BizCity_TwinShell_Event_Bus::emit(
					$state->run_id,
					BizCity_TwinShell_Event_Bus::TYPE_TOOL_PROPOSED,
					[ 'call_id' => $call_id, 'tool_name' => $tool_name, 'args' => $args ]
				);
			}
			$tool_started = microtime( true );
			$tool_result  = $this->safe_execute_tool( $tool, $args, $ctx );
			$this->perf_record( 'tool', $tool_name, $tool_started );
			if ( class_exists( 'BizCity_TwinShell_Event_Bus' ) ) {
				$evt_payload = [
					'call_id'        => $call_id,
					'tool_name'      => $tool_name,
					'result_preview' => self::preview_for_event( $tool_result ),
				];
				// Vòng 4.5.0.b — Rule 8g F4: surface artifact_created so FE can auto-switch
				// to Canvas mode without parsing the truncated result_preview.
				if ( is_array( $tool_result ) && isset( $tool_result['artifact_created'] ) && is_array( $tool_result['artifact_created'] ) ) {
					$evt_payload['artifact_created'] = $tool_result['artifact_created'];
				}
				BizCity_TwinShell_Event_Bus::emit(
					$state->run_id,
					BizCity_TwinShell_Event_Bus::TYPE_TOOL_EXECUTED,
					$evt_payload
				);
			}
			$state->messages[] = [
				'role'    => 'tool',
				'name'    => $tool_name,
				'content' => wp_json_encode( $tool_result ),
			];

			// Vòng 3 / Sprint 7 hotfix — terminal tool result.
			// A tool may signal `_terminal=true` to stop the parent loop
			// immediately (e.g. Agent::as_tool() when the sub-run paused
			// for HIL approval — the user is now interacting with the
			// sub-agent's panel and the parent must NOT loop and re-spawn).
			// Finalize the parent run with the tool's `output` as the
			// assistant reply, no extra LLM call.
			if ( is_array( $tool_result ) && ! empty( $tool_result['_terminal'] ) ) {
				$final_text          = isset( $tool_result['output'] ) ? (string) $tool_result['output'] : '';
				$assistant_msg       = [ 'role' => 'assistant', 'content' => $final_text ];
				$this->session->add_items( [ $assistant_msg ] );
				$state->messages[]   = $assistant_msg;
				$state->status       = 'completed';
				$state->final_output = $final_text;
				if ( class_exists( 'BizCity_TwinShell_Event_Bus' ) ) {
					BizCity_TwinShell_Event_Bus::emit(
						$state->run_id,
						BizCity_TwinShell_Event_Bus::TYPE_FINAL,
						[ 'final_output' => $final_text ]
					);
				}
				$state->persist();
				return $state;
			}
			// Loop continues — LLM will see tool result and either call another tool or finalize.
		}

		// Exhausted iterations without a final answer → fail soft
		$state->status       = 'completed';
		$state->final_output = $state->final_output ?? 'Max tool iterations reached without a final answer.';
		$state->persist();
		return $state;
	}

	/* ================================================================
	 *  Internal helpers
	 * ================================================================ */

	/**
	 * Build the system prompt — agent instructions + prompt-based tool calling protocol.
	 */
	private function build_system_prompt( BizCity_TwinShell_Agent $agent, array $tool_specs ): string {
		$prompt = $agent->instructions;

		if ( empty( $tool_specs ) ) {
			return $prompt;
		}

		$tool_lines = [];
		foreach ( $tool_specs as $spec ) {
			$fn = $spec['function'] ?? [];
			$tool_lines[] = sprintf(
				'- %s — %s. Parameters: %s',
				$fn['name'] ?? '?',
				$fn['description'] ?? '',
				wp_json_encode( $fn['parameters'] ?? new stdClass() )
			);
		}

		$prompt .= "\n\n# AVAILABLE TOOLS\n" . implode( "\n", $tool_lines );
		$prompt .= "\n\n# TOOL CALLING PROTOCOL\n"
			. "When you need to call a tool, output ONLY a single JSON object on its own line, with no surrounding text or markdown fences:\n"
			. '{"tool":"<tool_name>","args":{...}}' . "\n"
			. "When you are ready to give a final answer to the user, output the answer directly (no JSON wrapper).\n"
			. "Never mix tool calls with prose. One turn = either ONE tool call OR ONE final answer.";

		return $prompt;
	}

	/**
	 * Parse an LLM reply into a tool call. Returns null if the reply is not a tool call.
	 *
	 * Accepts either:
	 *   - bare JSON: {"tool":"x","args":{...}}
	 *   - JSON inside ```json fenced block
	 *
	 * @param string $reply
	 * @return array|null  ['tool' => string, 'args' => array] or null
	 */
	private function parse_tool_call( string $reply ): ?array {
		$text = trim( $reply );
		if ( $text === '' ) return null;

		// Strip ```json ... ``` fence if present
		if ( preg_match( '/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m ) ) {
			$text = $m[1];
		}

		// Quick reject: must look like JSON object
		if ( $text === '' || $text[0] !== '{' ) return null;

		$decoded = json_decode( $text, true );
		if ( ! is_array( $decoded ) || empty( $decoded['tool'] ) || ! is_string( $decoded['tool'] ) ) {
			return null;
		}

		return [
			'tool' => $decoded['tool'],
			'args' => isset( $decoded['args'] ) && is_array( $decoded['args'] ) ? $decoded['args'] : [],
		];
	}

	/**
	 * Apply HIL decisions: execute approved pending tool calls, append rejection messages
	 * for rejected ones, then clear them from pending/interruptions.
	 *
	 * @return bool  True if at least one decision was applied.
	 */
	private function apply_decisions(
		BizCity_Twin_RunState $state,
		BizCity_TwinShell_Agent $agent,
		array $decisions,
		array $ctx
	): bool {
		if ( empty( $decisions ) || empty( $state->pending_tool_calls ) ) {
			return false;
		}

		$tool_map = [];
		foreach ( $agent->get_enabled_tools( $ctx ) as $t ) {
			$tool_map[ $t->name ] = $t;
		}

		$resolved_call_ids = [];

		foreach ( $state->pending_tool_calls as $pending ) {
			$call_id = $pending['call_id'] ?? '';
			if ( ! isset( $decisions[ $call_id ] ) ) continue;

			$decision = $decisions[ $call_id ]['decision'] ?? '';
			$reason   = $decisions[ $call_id ]['reason'] ?? '';
			$tool_name = $pending['tool_name'] ?? '';
			$args      = $pending['args'] ?? [];

			if ( $decision === 'approved' && isset( $tool_map[ $tool_name ] ) ) {
				$tool_started = microtime( true );
				$tool_result  = $this->safe_execute_tool( $tool_map[ $tool_name ], $args, $ctx );
				$this->perf_record( 'tool_resume', $tool_name, $tool_started );
				$state->messages[] = [
					'role'    => 'tool',
					'name'    => $tool_name,
					'content' => wp_json_encode( $tool_result ),
				];
				// Vòng 4.5.5d — Emit tool_executed on resume so FE can pick up
				// artifact_created (Rule 8g F4) and auto-open Canvas iframe.
				// Without this, mindmap/doc agents that bridge to Studio show
				// the spinner then collapse to "Chưa có artifact" because no
				// event ever surfaces the artifact_created payload.
				if ( class_exists( 'BizCity_TwinShell_Event_Bus' ) ) {
					$evt_payload = [
						'call_id'        => $call_id,
						'tool_name'      => $tool_name,
						'result_preview' => self::preview_for_event( $tool_result ),
					];
					if ( is_array( $tool_result ) && isset( $tool_result['artifact_created'] ) && is_array( $tool_result['artifact_created'] ) ) {
						$evt_payload['artifact_created'] = $tool_result['artifact_created'];
					}
					BizCity_TwinShell_Event_Bus::emit(
						$state->run_id,
						BizCity_TwinShell_Event_Bus::TYPE_TOOL_EXECUTED,
						$evt_payload
					);
				}
			} elseif ( $decision === 'rejected' ) {
				$state->messages[] = [
					'role'    => 'tool',
					'name'    => $tool_name,
					'content' => wp_json_encode( [
						'ok'    => false,
						'error' => 'rejected_by_user',
						'reason' => $reason ?: 'User rejected this tool call.',
					] ),
				];
			} else {
				continue; // unknown decision or unknown tool
			}

			$resolved_call_ids[] = $call_id;
		}

		if ( empty( $resolved_call_ids ) ) return false;

		// Remove resolved calls from pending + interruptions
		$state->pending_tool_calls = array_values( array_filter(
			$state->pending_tool_calls,
			static function ( $p ) use ( $resolved_call_ids ) {
				return ! in_array( $p['call_id'] ?? '', $resolved_call_ids, true );
			}
		) );
		$state->interruptions = array_values( array_filter(
			$state->interruptions,
			static function ( $i ) use ( $resolved_call_ids ) {
				return ! in_array( $i['call_id'] ?? '', $resolved_call_ids, true );
			}
		) );

		return true;
	}

	/**
	 * Execute a tool with try/catch — never throw out of the runner.
	 *
	 * @return array  Always an array; on failure: ['ok'=>false,'error'=>'…']
	 */
	private function safe_execute_tool( BizCity_TwinShell_Tool $tool, array $args, array $ctx ): array {
		try {
			$out = $tool->execute( $args, $ctx );
			if ( is_array( $out ) ) return $out;
			return [ 'ok' => true, 'result' => $out ];
		} catch ( \Throwable $e ) {
			return [
				'ok'    => false,
				'error' => 'tool_exception',
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Normalize $input into [{role, content}] format.
	 *
	 * @param array|string $input
	 * @return array<int, array>
	 */
	private function normalize_input( $input ): array {
		if ( is_string( $input ) ) {
			return [ [ 'role' => 'user', 'content' => $input ] ];
		}

		if ( is_array( $input ) ) {
			// Already a messages array: [{role,content},...] — return as-is
			if ( isset( $input[0] ) && is_array( $input[0] ) && isset( $input[0]['role'] ) ) {
				return $input;
			}
			// Single associative message: {role, content}
			if ( isset( $input['role'] ) ) {
				return [ $input ];
			}
		}

		return [];
	}

	/**
	 * Build a failed RunState without touching the DB (agent not found).
	 *
	 * @param string $agent_name
	 * @param array  $ctx
	 * @param string $error
	 * @return BizCity_Twin_RunState
	 */
	private function make_failed_state( string $agent_name, array $ctx, string $error ): BizCity_Twin_RunState {
		$state         = BizCity_Twin_RunState::create( $agent_name, $ctx );
		$state->status = 'failed';
		$state->error  = $error;
		return $state;
	}

	/**
	 * Mark a RunState as failed, persist, and return it.
	 *
	 * @param BizCity_Twin_RunState $state
	 * @param string                $error
	 * @return BizCity_Twin_RunState
	 */
	private function finalize_failed( BizCity_Twin_RunState $state, string $error ): BizCity_Twin_RunState {
		$state->status = 'failed';
		$state->error  = $error;
		if ( class_exists( 'BizCity_TwinShell_Event_Bus' ) ) {
			BizCity_TwinShell_Event_Bus::emit(
				$state->run_id,
				BizCity_TwinShell_Event_Bus::TYPE_FAILED,
				[ 'error' => $error ]
			);
		}
		$state->persist();
		return $state;
	}

	/**
	 * Build a small, JSON-safe preview of a tool result for event payloads.
	 * Avoids dumping huge LLM outputs into the events table.
	 */
	private static function preview_for_event( $value, int $max_len = 400 ): string {
		$json = is_string( $value ) ? $value : wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			$json = '';
		}
		if ( strlen( $json ) <= $max_len ) {
			return $json;
		}
		return substr( $json, 0, $max_len ) . '…';
	}
}
