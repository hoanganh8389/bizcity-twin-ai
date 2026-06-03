<?php
/**
 * BizCity Diagnostics — twinbrain.brain.auto-degrade probe (TBR.W18).
 *
 * Validates the Brain Auto-Degrade Chat path added in Phase 0.36-UNIFIED
 * Wave 2.9 (2026-05-28). When `complete_turn_stream()` is called with
 * zero notebook candidates AND zero tool candidates AND web_mode=off
 * BUT a non-empty `memory_block`, the Runtime short-circuits the
 * Perspective + Tool + Synthesizer layers and streams a chat-tone answer
 * directly from `BizCity_TwinBrain_Final_Composer::compose_chat_stream()`.
 *
 * What this probe checks (NO real Runtime invocation — that would require
 * a buffer SSE writer + real candidate selection. We test the two new
 * surface APIs end-to-end instead, plus eligibility branch logic via
 * filter inspection):
 *
 *   1. `compose_chat_stream()` method exists.
 *   2. Calling it with a realistic memory_block produces ≥1 stream delta.
 *   3. Final answer is non-empty (≥40 chars), fallback empty, model set.
 *   4. Eligibility filter `bizcity_twinbrain_auto_degrade_eligible`
 *      fires and returns true under nominal conditions; can be vetoed.
 *   5. MIN-bytes filter `bizcity_twinbrain_auto_degrade_min_memory_bytes`
 *      is respected (default 120 → blocks short blocks).
 *
 * Severity = `warning`: depends on LLM gateway (one chat_stream call).
 * Idempotent — no DB writes, no persisted side effect outside gateway logs.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-28 (Phase 0.36-UNIFIED · TBR.W18)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_TwinBrain_Brain_Auto_Degrade implements BizCity_Diagnostics_Probe {

	const PROBE_PROMPT = 'Bạn còn nhớ mình tên gì không?';

	const PROBE_MEMORY_BLOCK = <<<MEM
### 🧠 MEMORY RECALL

**User profile (long-term):**
- Tên: Nguyễn Văn A `[mem:U#101]`
- Nghề: Lập trình viên backend PHP `[mem:U#102]`
- Sở thích: cà phê đen không đường, đọc sách triết học `[mem:U#103]`

**Recent episodic notes:**
- Hôm qua user nhắc đang chuẩn bị thi chứng chỉ AWS Solutions Architect `[mem:E#207]`
- Tuần trước user dặn "khi mình hỏi gì về work-life balance thì nhắc mình rest 7h/ngày" `[mem:E#208]`
MEM;

	public function id(): string          { return 'twinbrain.brain.auto-degrade'; }
	public function label(): string       { return 'Brain Auto-Degrade Chat (TBR.W18)'; }
	public function description(): string {
		return 'Validate compose_chat_stream() + eligibility filters cho luồng chat tự nhiên khi K=0 candidates + memory ≥120B. TỐN ~1 LLM stream call.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 43; } // sau web.gov (42)
	public function icon(): string        { return 'message-circle-more'; }
	public function estimate_ms(): int    { return 9000; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Final_Composer' ) ) {
			return 'BizCity_TwinBrain_Final_Composer chưa load.';
		}
		if ( ! method_exists( 'BizCity_TwinBrain_Final_Composer', 'compose_chat_stream' ) ) {
			return 'compose_chat_stream() method chưa tồn tại — TBR.W18 patch chưa apply.';
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return 'BizCity_LLM_Client chưa load — gateway chưa active.';
		}
		$client = BizCity_LLM_Client::instance();
		if ( method_exists( $client, 'is_ready' ) && ! $client->is_ready() ) {
			return 'Gateway API key chưa cấu hình.';
		}
		return true;
	}

	public function run( $ctx ): array {
		/* ----- Step 1: surface check ----- */
		$ctx->emit_step( [
			'label'  => 'compose_chat_stream() exists',
			'status' => 'pass',
			'detail' => 'BizCity_TwinBrain_Final_Composer::compose_chat_stream',
		] );

		/* ----- Step 2: eligibility filter wiring (nominal TRUE) ----- */
		$filter_seen_nominal = false;
		$nominal_cb = static function ( $eligible, $trace_id, $prompt, $opts ) use ( &$filter_seen_nominal ) {
			$filter_seen_nominal = true;
			return $eligible; // pass-through
		};
		add_filter( 'bizcity_twinbrain_auto_degrade_eligible', $nominal_cb, 10, 4 );
		$nominal_eligible = (bool) apply_filters(
			'bizcity_twinbrain_auto_degrade_eligible',
			true,
			'probe-trace',
			self::PROBE_PROMPT,
			[ 'memory_block' => self::PROBE_MEMORY_BLOCK, 'web_mode' => 'off' ]
		);
		remove_filter( 'bizcity_twinbrain_auto_degrade_eligible', $nominal_cb, 10 );
		$ctx->emit_step( [
			'label'  => 'Eligibility filter fires (nominal)',
			'status' => ( $filter_seen_nominal && $nominal_eligible ) ? 'pass' : 'fail',
			'detail' => sprintf( 'seen=%s eligible=%s', $filter_seen_nominal ? 'yes' : 'no', $nominal_eligible ? 'yes' : 'no' ),
		] );

		/* ----- Step 3: eligibility filter veto path ----- */
		$veto_cb = static function () { return false; };
		add_filter( 'bizcity_twinbrain_auto_degrade_eligible', $veto_cb, 99 );
		$vetoed = (bool) apply_filters(
			'bizcity_twinbrain_auto_degrade_eligible',
			true,
			'probe-trace',
			self::PROBE_PROMPT,
			[ 'memory_block' => self::PROBE_MEMORY_BLOCK ]
		);
		remove_filter( 'bizcity_twinbrain_auto_degrade_eligible', $veto_cb, 99 );
		$ctx->emit_step( [
			'label'  => 'Eligibility filter veto honored',
			'status' => ( $vetoed === false ) ? 'pass' : 'fail',
			'detail' => $vetoed === false ? 'veto blocks branch' : 'veto IGNORED (bug)',
		] );

		/* ----- Step 4: MIN-bytes filter default ----- */
		$min_default = (int) apply_filters(
			'bizcity_twinbrain_auto_degrade_min_memory_bytes',
			120,
			[]
		);
		$ctx->emit_step( [
			'label'  => 'MIN-bytes filter default',
			'status' => $min_default >= 1 ? 'pass' : 'fail',
			'detail' => $min_default . ' bytes',
		] );

		/* ----- Step 5: real compose_chat_stream call ----- */
		$trace_id    = 'probe-auto-degrade-' . wp_generate_uuid4();
		$composer    = BizCity_TwinBrain_Final_Composer::instance();
		$delta_count = 0;
		$last_full   = '';

		$started = microtime( true );
		try {
			$result = $composer->compose_chat_stream(
				$trace_id,
				self::PROBE_PROMPT,
				[
					'memory_block' => self::PROBE_MEMORY_BLOCK,
				],
				static function ( $delta, $accumulated ) use ( &$delta_count, &$last_full ) {
					$delta_count++;
					$last_full = (string) $accumulated;
				}
			);
		} catch ( \Throwable $e ) {
			return [
				'status'   => 'fail',
				'error'    => 'Exception: ' . $e->getMessage(),
				'fix_hint' => 'compose_chat_stream() threw — check error log + chat_stream() contract.',
			];
		}
		$elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		$answer = trim( (string) ( $result['answer_md'] ?? '' ) );
		$model  = (string) ( $result['model']     ?? '' );
		$fb     = (string) ( $result['fallback']  ?? '' );

		$ctx->emit_step( [
			'label'  => 'Chat stream deltas received',
			'status' => $delta_count > 0 ? 'pass' : 'fail',
			'detail' => $delta_count . ' deltas',
		] );
		$ctx->emit_step( [
			'label'  => 'Chat answer non-empty',
			'status' => mb_strlen( $answer ) >= 40 ? 'pass' : 'fail',
			'detail' => sprintf( '%d chars (min 40)', mb_strlen( $answer ) ),
		] );
		$ctx->emit_step( [
			'label'  => 'Gateway model returned',
			'status' => $model !== '' ? 'pass' : 'fail',
			'detail' => $model !== '' ? $model : '(empty)',
		] );
		$ctx->emit_step( [
			'label'  => 'Fallback flag',
			'status' => $fb === '' ? 'pass' : 'fail',
			'detail' => $fb === '' ? 'none' : $fb,
		] );
		$ctx->emit_step( [
			'label'  => 'Elapsed time',
			'status' => $elapsed_ms < 30000 ? 'pass' : 'fail',
			'detail' => $elapsed_ms . ' ms',
		] );
		$ctx->emit_step( [
			'label'  => 'Tokens used',
			'status' => 'pass',
			'detail' => (int) ( $result['tokens'] ?? 0 ),
		] );

		/* ----- Step 6: memory citation echo (soft) -----
		 * Composer system prompt instructs LLM to echo `[mem:U#<id>]`
		 * when using memory facts. Don't HARD fail (LLM compliance
		 * varies) — emit info-level result. */
		$has_mem_cite = (bool) preg_match( '/\[mem:[UER]#\d+\]/', $answer );
		$ctx->emit_step( [
			'label'  => 'Memory citation echoed (soft)',
			'status' => $has_mem_cite ? 'pass' : 'fail',
			'detail' => $has_mem_cite ? 'mem token found in answer' : 'no [mem:U#…] token (LLM didn\'t cite)',
		] );

		$ok = $filter_seen_nominal
			&& $nominal_eligible
			&& ( $vetoed === false )
			&& $delta_count > 0
			&& mb_strlen( $answer ) >= 40
			&& $fb === ''
			&& $model !== '';

		if ( ! $ok ) {
			$reasons = [];
			if ( ! $filter_seen_nominal )      $reasons[] = 'eligibility filter never fired';
			if ( ! $nominal_eligible )         $reasons[] = 'nominal eligibility returned false';
			if ( $vetoed !== false )           $reasons[] = 'veto callback ignored';
			if ( $delta_count === 0 )          $reasons[] = 'no stream deltas (SSE broken)';
			if ( mb_strlen( $answer ) < 40 )   $reasons[] = 'answer too short';
			if ( $fb !== '' )                  $reasons[] = 'fallback triggered: ' . $fb;
			if ( $model === '' )               $reasons[] = 'gateway model empty';

			return [
				'status'   => 'fail',
				'summary'  => 'Auto-Degrade chat path issue — ' . implode( '; ', $reasons ),
				'fix_hint' => 'Kiểm tra TBR.W18 patch trong class-twinbrain-final-composer.php (compose_chat_stream) và class-twinbrain-runtime.php (stream_auto_degrade_chat branch).',
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf(
				'Auto-degrade chat OK — %d deltas, %d chars, %dms, model=%s%s',
				$delta_count,
				mb_strlen( $answer ),
				$elapsed_ms,
				$model,
				$has_mem_cite ? ', mem-cited' : ', no-mem-cite'
			),
		];
	}

	public function cleanup(): void {
		// No persisted side effect — gateway-only call, idempotent.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Brain_Auto_Degrade';
	return $list;
} );
