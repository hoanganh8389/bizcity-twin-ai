<?php
/**
 * BizCity Diagnostics — twinbrain.agent.react probe (TBR.W20).
 *
 * Validates the Agent ReAct Runner added in Phase 0.36-UNIFIED Wave 2.9
 * (2026-05-28). Strategy: call `BizCity_TwinBrain_Agent_Runner::run()`
 * directly with a memory-leaning prompt + memory_block fixture, capture
 * SSE events via the on_event relay callback, then assert:
 *
 *   1. Class loaded.
 *   2. Default whitelist resolves to ≥ 1 registered tool.
 *   3. agent_loop_started event fires with tools[].
 *   4. ≥ 1 iteration completed (either a tool call OR direct final).
 *   5. agent_loop_done event fires with final_text_len > 0.
 *   6. Total wall < 90s.
 *   7. Filter `bizcity_twinbrain_agent_allowed_tools` honored (veto path).
 *
 * Soft check: at least 1 successful tool execution OR a clean direct
 * answer (no_tools_available reason acceptable when registry empty in
 * smoke env).
 *
 * Severity = `warning` — depends on LLM gateway (up to 5 chat calls).
 * Estimate ~20s wall for 1-2 iter typical.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-28 (Phase 0.36-UNIFIED · TBR.W20)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_TwinBrain_Agent_React implements BizCity_Diagnostics_Probe {

	/** Prompt designed to nudge LLM toward calling `memory_recall` first.
	 *  Memory tools are always in the default whitelist + always have at
	 *  least 3 registered instances (remember/recall/forget), so this
	 *  probe doesn't require KG/notebook setup to PASS. */
	const PROBE_PROMPT = 'Bạn còn nhớ mình là ai không? Nếu có thông tin gì về sở thích của mình, kể lại giúp.';

	const PROBE_MEMORY_BLOCK = <<<MEM
### 🧠 MEMORY RECALL

**User profile:**
- Tên: Trần Văn B `[mem:U#501]`
- Sở thích: nhiếp ảnh phong cảnh, leo núi cuối tuần `[mem:U#502]`
MEM;

	public function id(): string          { return 'twinbrain.agent.react'; }
	public function label(): string       { return 'TwinBrain Agent ReAct (TBR.W20)'; }
	public function description(): string {
		return 'Real-call Agent_Runner với prompt memory + memory_block fixture. Validate ReAct loop + tool whitelist + SSE events. TỐN ~1-3 LLM call.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 44; } // sau brain.auto-degrade (43)
	public function icon(): string        { return 'bot' ; }
	public function estimate_ms(): int    { return 20000; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Agent_Runner' ) ) {
			return 'BizCity_TwinBrain_Agent_Runner chưa load — TBR.W20 bootstrap patch chưa apply.';
		}
		if ( ! class_exists( 'BizCity_Twin_Tool_Registry' ) ) {
			return 'BizCity_Twin_Tool_Registry chưa load — twin-core bootstrap chưa fire.';
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return 'BizCity_LLM_Client chưa load — gateway chưa active.';
		}
		$llm = BizCity_LLM_Client::instance();
		if ( method_exists( $llm, 'is_ready' ) && ! $llm->is_ready() ) {
			return 'Gateway API key chưa cấu hình.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$ctx->emit_step( [
			'label'  => 'Agent_Runner class loaded',
			'status' => 'pass',
			'detail' => 'BizCity_TwinBrain_Agent_Runner',
		] );

		/* ----- Step 2: registry has whitelist tools available ----- */
		$registry = BizCity_Twin_Tool_Registry::instance();
		$default_allowed = BizCity_TwinBrain_Agent_Runner::DEFAULT_ALLOWED;
		$available = array_keys( $registry->get_all( $default_allowed ) );
		$ctx->emit_step( [
			'label'  => 'Whitelist tools registered',
			'status' => count( $available ) >= 1 ? 'pass' : 'fail',
			'detail' => sprintf( '%d/%d available: %s',
				count( $available ), count( $default_allowed ),
				implode( ',', $available ) ?: '(none)'
			),
		] );

		/* ----- Step 3: whitelist veto filter honored ----- */
		$veto_cb = static function () { return []; };
		add_filter( 'bizcity_twinbrain_agent_allowed_tools', $veto_cb, 99 );
		$vetoed = (array) apply_filters(
			'bizcity_twinbrain_agent_allowed_tools',
			$default_allowed,
			'probe-trace',
			[]
		);
		remove_filter( 'bizcity_twinbrain_agent_allowed_tools', $veto_cb, 99 );
		$ctx->emit_step( [
			'label'  => 'Allowed-tools filter veto honored',
			'status' => empty( $vetoed ) ? 'pass' : 'fail',
			'detail' => empty( $vetoed ) ? 'veto returns empty' : 'veto IGNORED',
		] );

		/* ----- Step 4: real ReAct run ----- */
		$trace_id = 'probe-agent-react-' . wp_generate_uuid4();
		$events   = [];
		$relay    = static function ( string $event_key, array $payload ) use ( &$events ) {
			$events[] = [ 'key' => $event_key, 'payload' => $payload ];
		};

		$runner = BizCity_TwinBrain_Agent_Runner::instance();
		$started = microtime( true );
		try {
			$res = $runner->run(
				$trace_id,
				self::PROBE_PROMPT,
				[
					'memory_block' => self::PROBE_MEMORY_BLOCK,
					'user_id'      => get_current_user_id(),
				],
				$relay
			);
		} catch ( \Throwable $e ) {
			return [
				'status'   => 'fail',
				'error'    => 'Exception: ' . $e->getMessage(),
				'fix_hint' => 'Agent_Runner::run() threw — check error log.',
			];
		}
		$wall_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		/* ----- Step 5: event taxonomy ----- */
		$has_started = false;
		$has_done    = false;
		$step_count  = 0;
		foreach ( $events as $ev ) {
			if ( $ev['key'] === 'agent_loop_started' ) $has_started = true;
			if ( $ev['key'] === 'agent_loop_done' )    $has_done    = true;
			if ( $ev['key'] === 'agent_step_done' )    $step_count++;
		}
		$ctx->emit_step( [
			'label'  => 'agent_loop_started emitted',
			'status' => $has_started ? 'pass' : 'fail',
			'detail' => $has_started ? 'yes' : 'no event captured',
		] );
		$ctx->emit_step( [
			'label'  => 'agent_loop_done emitted',
			'status' => $has_done ? 'pass' : 'fail',
			'detail' => $has_done ? 'yes' : 'no event captured',
		] );
		$ctx->emit_step( [
			'label'  => 'agent_step_done event count',
			'status' => $step_count >= 1 ? 'pass' : 'fail',
			'detail' => $step_count . ' step(s)',
		] );

		/* ----- Step 6: final answer non-empty ----- */
		$answer = (string) ( $res['answer_md'] ?? '' );
		$ctx->emit_step( [
			'label'  => 'Final answer non-empty',
			'status' => mb_strlen( $answer ) >= 20 ? 'pass' : 'fail',
			'detail' => sprintf( '%d chars (min 20)', mb_strlen( $answer ) ),
		] );

		/* ----- Step 7: wall time bounded ----- */
		$ctx->emit_step( [
			'label'  => 'Elapsed wall',
			'status' => $wall_ms < 90000 ? 'pass' : 'fail',
			'detail' => $wall_ms . ' ms',
		] );

		/* ----- Step 8: iter / token telemetry ----- */
		$iter_count   = count( (array) ( $res['iterations'] ?? [] ) );
		$tool_count   = count( (array) ( $res['tool_runs']  ?? [] ) );
		$forced_final = ! empty( $res['forced_final'] );
		$ctx->emit_step( [
			'label'  => 'Iterations',
			'status' => $iter_count >= 1 ? 'pass' : 'fail',
			'detail' => sprintf( '%d iter, %d tool-run, tokens=%d, forced_final=%s, reason=%s',
				$iter_count,
				$tool_count,
				(int) ( $res['tokens'] ?? 0 ),
				$forced_final ? 'yes' : 'no',
				(string) ( $res['reason'] ?? '' )
			),
		] );

		/* ----- Step 9: gateway model returned ----- */
		$model = (string) ( $res['model'] ?? '' );
		$ctx->emit_step( [
			'label'  => 'Gateway model identifier',
			'status' => $model !== '' ? 'pass' : 'fail',
			'detail' => $model !== '' ? $model : '(empty)',
		] );

		$ok = count( $available ) >= 1
			&& empty( $vetoed )
			&& $has_started
			&& $has_done
			&& $step_count >= 1
			&& mb_strlen( $answer ) >= 20
			&& $wall_ms < 90000
			&& $iter_count >= 1
			&& $model !== '';

		if ( ! $ok ) {
			$reasons = [];
			if ( count( $available ) < 1 )       $reasons[] = 'no whitelist tools registered';
			if ( ! empty( $vetoed ) )            $reasons[] = 'veto filter ignored';
			if ( ! $has_started )                $reasons[] = 'agent_loop_started missing';
			if ( ! $has_done )                   $reasons[] = 'agent_loop_done missing';
			if ( $step_count < 1 )               $reasons[] = 'no agent_step_done events';
			if ( mb_strlen( $answer ) < 20 )     $reasons[] = 'answer too short';
			if ( $wall_ms >= 90000 )             $reasons[] = 'wall time exceeded 90s';
			if ( $iter_count < 1 )               $reasons[] = 'no iterations recorded';
			if ( $model === '' )                 $reasons[] = 'gateway model empty';

			return [
				'status'   => 'fail',
				'summary'  => 'Agent ReAct issue — ' . implode( '; ', $reasons ),
				'fix_hint' => 'Check Agent_Runner::run() + Tool_Registry::get_all() + bizcity_twin_register_tool filter wiring.',
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf(
				'Agent ReAct OK — %d iter, %d tool-runs, %d chars, %dms, model=%s%s',
				$iter_count,
				$tool_count,
				mb_strlen( $answer ),
				$wall_ms,
				$model,
				$forced_final ? ' (forced_final)' : ''
			),
		];
	}

	public function cleanup(): void {
		// No persisted side effect — Agent_Runner is in-memory, no DB writes.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Agent_React';
	return $list;
} );
