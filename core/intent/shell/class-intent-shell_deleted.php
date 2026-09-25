<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent\Shell
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.16 / Vòng 4 — Task 4.16.1
 * Slim entry point that replaces `BizCity_Intent_Engine::process()` (~6488 LOC)
 * with a ~150-line orchestration over the shared TwinShell runtime.
 *
 *   1. Pre-rules — local short-circuit (slash / cancel / approval / retry).
 *   2. Context  — collect WP layers (user, channel, capabilities, locale…).
 *   3. Hint    — regex `intent_kind` so triage agent can early-route.
 *   4. Runner  — delegate to `intent_root` agent (registered Sprint 2).
 *   5. Map    — translate `BizCity_Twin_RunState` → legacy response shape.
 *
 * Sprint 1 status:
 *   • Steps 1, 2, 3, 5 implemented.
 *   • Step 4 returns a 'pending_migration' stub action because the
 *     `intent_root` triage agent + sub-agents will be registered in Sprint 2
 *     (Task 4.16.5 + Tool_Migrator).
 *
 * The class is therefore SAFE TO LOAD but not yet wired into Intent_Engine.
 *
 * @since 4.0.0 (Vòng 4)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Intent_Shell {

	/** @var BizCity_Intent_Pre_Rules */
	private $pre_rules;

	/** @var BizCity_Intent_Context_Collector */
	private $collector;

	/** @var BizCity_Intent_Shadow_Diff */
	private $diff;

	/** @var BizCity_Intent_Session_Adapter|null */
	private $session_adapter;

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self(
				new BizCity_Intent_Pre_Rules(),
				new BizCity_Intent_Context_Collector(),
				new BizCity_Intent_Shadow_Diff(),
				class_exists( 'BizCity_Intent_Session_Adapter' ) ? new BizCity_Intent_Session_Adapter() : null
			);
		}
		return self::$instance;
	}

	public function __construct(
		BizCity_Intent_Pre_Rules $pre_rules,
		BizCity_Intent_Context_Collector $collector,
		BizCity_Intent_Shadow_Diff $diff,
		$session_adapter = null
	) {
		$this->pre_rules       = $pre_rules;
		$this->collector       = $collector;
		$this->diff            = $diff;
		$this->session_adapter = $session_adapter;

		// Sprint 3 — let pre-rules dispatch cancel via the session adapter.
		if ( $session_adapter && method_exists( $pre_rules, 'set_session_adapter' ) ) {
			$pre_rules->set_session_adapter( $session_adapter );
		}
	}

	/**
	 * Main entry. Mirrors `BizCity_Intent_Engine::process()` signature so the
	 * shadow / cutover hook can swap implementations without touching callers.
	 *
	 * @param array $params
	 * @return array  Legacy-shaped response.
	 */
	public function handle( array $params ): array {
		$started  = microtime( true );
		$phase_t  = []; // Sprint 5 — per-phase timings (ms)
		$mark = static function ( string $name, float $from ) use ( &$phase_t ): float {
			$now = microtime( true );
			$phase_t[ $name ] = (int) round( ( $now - $from ) * 1000 );
			return $now;
		};
		$t = $started;

		// 1. Pre-rules — try a deterministic local response first.
		$pre = $this->pre_rules->try_match( $params );
		$t   = $mark( 'pre_rules', $t );
		if ( is_array( $pre ) ) {
			error_log( '[Intent_Shell perf] pre_rules HIT total=' . $phase_t['pre_rules'] . 'ms' );
			return $this->finalize( $pre, $started, [ 'route' => 'pre_rules', 'phase_timings' => $phase_t ] );
		}

		// 2. Collect context.
		$ctx = $this->collector->collect( $params );
		$t   = $mark( 'context_collect', $t );

		// 2b. Attach legacy conversation row so sub-agents inherit conversation_id.
		if ( $this->session_adapter ) {
			$ctx = $this->session_adapter->attach( $params, $ctx );
			$t   = $mark( 'session_attach', $t );
		}

		// 3. Hybrid intent_kind hint (null = let triage decide).
		$ctx['intent_kind'] = $this->pre_rules->detect_intent_kind( $params ) ?? 'auto';
		$t = $mark( 'intent_kind_hint', $t );

		// Sprint 5 — Fast path: when regex pre-rules already classified the
		// intent_kind, skip the `intent_root` triage agent entirely and call
		// the matching sub-agent directly. Saves ~2.5s per request (one LLM
		// call). Triage agent is only needed when intent_kind=auto.
		$kind_to_agent = [
			'chat'     => 'intent_knowledge',
			'task'     => 'intent_execution',
			'creative' => 'intent_skill_workflow',
		];
		$direct_agent = $kind_to_agent[ $ctx['intent_kind'] ] ?? null;

		// 4. Delegate to runner (only if runtime + agent registered).
		if (
			class_exists( 'BizCity_Twin_Runner' )
			&& class_exists( 'BizCity_Twin_Agent_Registry' )
			&& class_exists( 'BizCity_Twin_Rolling_Session' )
			&& BizCity_Twin_Agent_Registry::instance()->has( 'intent_root' )
		) {
			$registry = BizCity_Twin_Agent_Registry::instance();

			// Pick triage agent unless we have a direct hit AND it's registered.
			$entry_agent = 'intent_root';
			if ( $direct_agent !== null && $registry->has( $direct_agent ) ) {
				$entry_agent = $direct_agent;
				error_log( '[Intent_Shell perf] FAST-PATH skip triage → ' . $direct_agent . ' (intent_kind=' . $ctx['intent_kind'] . ')' );
			}

			$session  = new BizCity_Twin_Rolling_Session();
			$runner   = new BizCity_Twin_Runner( $registry, $session );
			$t        = $mark( 'runner_init', $t );

			try {
				$message_in = (string) ( $params['message'] ?? '' );

				// Sprint 3 — prepend rolling-memory + intent_kind hint as a
				// short context block so the triage agent sees prior goals.
				$preface_lines = [];
				if ( ! empty( $ctx['intent_kind'] ) && $ctx['intent_kind'] !== 'auto' ) {
					$preface_lines[] = '[ctx] intent_kind=' . $ctx['intent_kind'];
				}
				if ( ! empty( $ctx['conversation_label'] ) ) {
					$preface_lines[] = '[ctx] active_goal=' . $ctx['conversation_label'];
				}
				if ( ! empty( $ctx['rolling_memory_summary'] ) ) {
					$preface_lines[] = $ctx['rolling_memory_summary'];
				}

				// Wave 10d.5 — Graph-RAG injection for knowledge path.
				// Pre-fix: intent_knowledge agent had `help_guide`/`find_customer`
				// tools but NO `search_kg` and NO Twin_Context_Resolver hook → it
				// answered from training data, never citing user's KG. Now we
				// pre-fetch passages via the same resolver TwinChat uses and
				// prepend them as a Knowledge Context block so the LLM cites
				// inline ([src:N#pM]). Notebook scope is taken from
				// context_overrides.notebook_id (set by TwinChat) or skipped.
				$nb_id = (int) ( $ctx['notebook_id'] ?? $params['context_overrides']['notebook_id'] ?? 0 );
				if (
					$entry_agent === 'intent_knowledge'
					&& $nb_id > 0
					&& trim( $message_in ) !== ''
					&& class_exists( 'BizCity_Twin_Context_Resolver' )
					&& ( ! function_exists( 'bizcity_kg_is_main_task' )
						|| bizcity_kg_is_main_task( 'intent_shell', 'chat', $message_in ) )
				) {
					try {
						$resolved = BizCity_Twin_Context_Resolver::resolve(
							[ 'plugin' => 'intent_shell', 'scope_id' => $nb_id, 'scope_type' => 'notebook' ],
							$message_in,
							[ 'use_kg' => true, 'top_k' => 5 ]
						);
						if ( ! empty( $resolved['context_block'] ) ) {
							$preface_lines[] = $resolved['context_block'];
							$preface_lines[] = '=== HƯỚNG DẪN TRẢ LỜI ===';
							$preface_lines[] = 'Trả lời câu hỏi dưới đây CHỈ dựa trên Knowledge Context ở trên. Cite mọi fact bằng nhãn [src:N#pM] xuất hiện đầu mỗi passage. Nếu Knowledge Context không có thông tin, nói rõ "chưa có nguồn".';
							// Stash for response meta (so the cron shadow_compare/dashboard
							// can show how many passages reached the agent).
							$ctx['_kg_passages_n'] = is_array( $resolved['passages'] ?? null )
								? count( $resolved['passages'] )
								: 0;
						}
					} catch ( \Throwable $e ) {
						error_log( '[Intent_Shell] KG resolve failed: ' . $e->getMessage() );
					}
				}

				if ( $preface_lines ) {
					$message_in = implode( "\n", $preface_lines ) . "\n\n---\n" . $message_in;
				}

				$state = $runner->run( $entry_agent, $message_in, $ctx );
				$t     = $mark( 'runner_run', $t );

				if ( $this->session_adapter ) {
					$this->session_adapter->commit( $state, $ctx );
					$t = $mark( 'session_commit', $t );
				}
				$response = $this->map_state_to_legacy_response( $state );

				// Pull per-LLM-call breakdown from runner if available.
				$runner_perf = method_exists( $runner, 'get_perf_log' ) ? $runner->get_perf_log() : [];
				$total_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
				error_log( sprintf(
					'[Intent_Shell perf] route=runner entry=%s total=%dms | %s | runner_steps=%s',
					$entry_agent,
					$total_ms,
					self::format_phase_timings( $phase_t ),
					self::format_runner_perf( $runner_perf )
				) );

				return $this->finalize(
					$response,
					$started,
					[
						'route'         => $entry_agent === 'intent_root' ? 'runner' : 'runner_fast',
						'entry_agent'   => $entry_agent,
						'run_id'        => $state->run_id,
						'phase_timings' => $phase_t,
						'runner_perf'   => $runner_perf,
					]
				);
			} catch ( Throwable $e ) {
				return $this->finalize(
					[
						'reply'  => '',
						'action' => 'error',
						'error'  => $e->getMessage(),
					],
					$started,
					[ 'route' => 'runner_error', 'phase_timings' => $phase_t ]
				);
			}
		}

		// 5. Sprint 1 fallback: agent not registered yet — surface a clear stub
		//    so callers know the shell loaded but isn't fully wired.
		return $this->finalize(
			[
				'reply'  => '',
				'action' => 'pending_migration',
				'meta'   => [
					'note' => 'Intent_Shell loaded but intent_root agent not registered yet (Sprint 2).',
					'ctx'  => $ctx,
				],
			],
			$started,
			[ 'route' => 'stub', 'phase_timings' => $phase_t ]
		);
	}

	private static function format_phase_timings( array $phase_t ): string {
		$out = [];
		foreach ( $phase_t as $k => $v ) { $out[] = $k . '=' . $v . 'ms'; }
		return implode( ' ', $out );
	}

	private static function format_runner_perf( array $perf ): string {
		if ( ! $perf ) { return '(none)'; }
		$out = [];
		foreach ( $perf as $row ) {
			$out[] = sprintf(
				'%s(%dms)',
				$row['kind'] ?? '?',
				(int) ( $row['ms'] ?? 0 )
			) . ( ! empty( $row['name'] ) ? ':' . $row['name'] : '' );
		}
		return implode( ' → ', $out );
	}

	/**
	 * Translate a TwinShell `RunState` into the legacy Intent_Engine response shape.
	 *
	 * @param BizCity_Twin_RunState $state
	 * @return array
	 */
	private function map_state_to_legacy_response( BizCity_Twin_RunState $state ): array {
		switch ( $state->status ) {
			case 'completed':
				return [
					'reply'           => (string) $state->final_output,
					'action'          => 'reply',
					'conversation_id' => (string) ( $state->context['conversation_id'] ?? '' ),
					'run_id'          => $state->run_id,
					'status'          => 'COMPLETED',
					'meta'            => [ 'source' => 'intent_shell' ],
				];

			case 'paused_hil':
				$interruptions = method_exists( $state, 'get_interruptions' )
					? $state->get_interruptions()
					: ( $state->interruptions ?? [] );
				return [
					'reply'           => '',
					'action'          => 'ask_user',
					'conversation_id' => (string) ( $state->context['conversation_id'] ?? '' ),
					'run_id'          => $state->run_id,
					'status'          => 'WAITING_APPROVAL',
					'interruptions'   => $interruptions,
					'meta'            => [ 'source' => 'intent_shell' ],
				];

			case 'failed':
				return [
					'reply'  => '',
					'action' => 'error',
					'run_id' => $state->run_id,
					'error'  => (string) $state->error,
					'status' => 'FAILED',
					'meta'   => [ 'source' => 'intent_shell' ],
				];

			default:
				return [
					'reply'  => '',
					'action' => 'passthrough',
					'run_id' => $state->run_id,
					'status' => strtoupper( (string) $state->status ),
				];
		}
	}

	private function finalize( array $response, float $started, array $meta = [] ): array {
		$response['shell_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( ! isset( $response['meta'] ) || ! is_array( $response['meta'] ) ) {
			$response['meta'] = [];
		}
		$response['meta'] = array_merge( $response['meta'], $meta );
		return $response;
	}

	/* ------------------------------------------------------------------
	 * Shadow-mode helper — invoked from the legacy Intent_Engine when
	 * `Intent_Shell_Config::is_shadow_enabled()` is true. Sprint 1 leaves
	 * this dormant — the legacy engine doesn't call it yet.
	 * ------------------------------------------------------------------ */
	public function shadow_compare( array $params, array $legacy_response, int $legacy_ms = 0 ): void {
		if ( ! BizCity_Intent_Shell_Config::is_shadow_enabled() ) {
			error_log( '[Intent_Shell shadow_compare] aborted: shadow_mode OFF' );
			return;
		}
		error_log( '[Intent_Shell shadow_compare] START user=' . ( $params['user_id'] ?? '?' ) . ' msg=' . substr( (string) ( $params['message'] ?? '' ), 0, 60 ) );
		$started = microtime( true );
		try {
			$shell    = $this->handle( $params );
			$shell_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
			error_log( '[Intent_Shell shadow_compare] handle() OK shell_ms=' . $shell_ms . ' reply_len=' . strlen( (string) ( $shell['reply'] ?? '' ) ) );

			$this->diff->log_async(
				$params,
				$legacy_response,
				$shell,
				[ 'legacy_ms' => $legacy_ms, 'shell_ms' => $shell_ms ]
			);
			error_log( '[Intent_Shell shadow_compare] diff logged OK' );
		} catch ( Throwable $e ) {
			error_log( '[Intent_Shell shadow_compare] EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
		}
	}
}
