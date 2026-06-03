<?php
/**
 * BizCity Diagnostics — web.deep.llm probe (Phase 0.36-UNIFIED TBR.W7-fix-1).
 *
 * Real-call probe for the TwinBrain Web Research **Deep** path (ReAct agent).
 * Unlike `class-probe-web-search-ping.php` (which only smoke-tests the Search
 * gateway), this probe actually invokes `BizCity_TwinBrain_Web_Deep::run()` on
 * a deterministic query and inspects the resulting row to surface common live
 * failure modes:
 *
 *   • `forced_final:budget_or_iter_cap` — iter / total budget too tight.
 *   • `final_answer` empty after force-final retry — gateway LLM unhealthy.
 *   • zero citations despite >0 results — ReAct loop not emitting [web:N].
 *   • iterations[] history with parse_error rows — prompt drift.
 *
 * Replaces the legacy `wp-cli twin:web-deep` debug command — operators can
 * now diagnose Web Deep regressions from Tools → BizCity Diagnostics without
 * needing shell access.
 *
 * Note: this probe DOES spend gateway budget (≈ 1-3 LLM calls, ≈ 1-3 search
 * calls). Marked `severity=warning` + estimate_ms=15000 so the Smoke Wizard
 * UI shows a confirm-before-run prompt. Idempotent: each run uses a fresh
 * trace_id and never persists side effects beyond gateway usage logs.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-21 (Phase 0.36-UNIFIED · TBR.W7-fix-1)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_Web_Deep_LLM implements BizCity_Diagnostics_Probe {

	/** Deterministic Vietnamese query — short, factual, fresh-news-friendly. */
	const PROBE_QUERY = 'tin tức thời tiết Hà Nội hôm nay';

	public function id(): string          { return 'web.deep.llm'; }
	public function label(): string       { return 'Web Deep (ReAct) — real LLM call'; }
	public function description(): string {
		return 'Gọi thật BizCity_TwinBrain_Web_Deep với query mẫu, kiểm tra ReAct loop, citation, force-final retry. TỐN budget gateway (~1-3 LLM + search call).';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 35; }
	public function icon(): string        { return 'globe-2'; }
	public function estimate_ms(): int    { return 15000; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Web_Deep' ) ) {
			return 'BizCity_TwinBrain_Web_Deep class chưa load — module twinbrain bootstrap không hoàn tất.';
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return 'BizCity_LLM_Client class chưa load — gateway (bizcity-llm-router) chưa active.';
		}
		if ( ! class_exists( 'BizCity_Search_Client' ) ) {
			return 'BizCity_Search_Client class chưa load — search gateway chưa active.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$ctx->emit_step( [
			'label'  => 'Query',
			'status' => 'pass',
			'detail' => self::PROBE_QUERY,
		] );

		$trace_id = 'probe-web-deep-' . wp_generate_uuid4();
		$deep     = BizCity_TwinBrain_Web_Deep::instance();

		$started = microtime( true );
		try {
			$row = $deep->run( $trace_id, self::PROBE_QUERY );
		} catch ( \Throwable $e ) {
			return [
				'status'   => 'fail',
				'error'    => 'Exception: ' . $e->getMessage(),
				'fix_hint' => 'Xem error log WordPress; có thể missing gateway config hoặc fatal trong dispatch_tool().',
			];
		}
		$elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( ! is_array( $row ) ) {
			return [
				'status'   => 'fail',
				'error'    => 'run() trả về non-array (' . gettype( $row ) . ')',
				'fix_hint' => 'Web_Deep::run() phải luôn trả về associative array — check contract.',
			];
		}

		// Step 2: iteration history (max 5 by design).
		$iters = (array) ( $row['iterations'] ?? [] );
		$ctx->emit_step( [
			'label'  => 'ReAct iterations',
			'status' => $iters ? 'pass' : 'fail',
			'detail' => sprintf( '%d / %d', count( $iters ), 5 ),
		] );

		// Step 3: results gathered (search hits).
		$results = (array) ( $row['results'] ?? [] );
		$ctx->emit_step( [
			'label'  => 'Search results gathered',
			'status' => $results ? 'pass' : 'fail',
			'detail' => count( $results ) . ' urls',
		] );

		// Step 4: citation tokens in final answer.
		$cite_n = (int) ( $row['citation_count'] ?? 0 );
		$ctx->emit_step( [
			'label'  => 'Citation tokens [web:N#URL]',
			'status' => $cite_n > 0 ? 'pass' : 'fail',
			'detail' => $cite_n . ' tokens',
		] );

		// Step 5: final answer composition.
		$answer  = trim( (string) ( $row['answer_md'] ?? '' ) );
		$is_stub = $answer !== '' && strpos( $answer, '_LLM synth unavailable' ) !== false;
		$ans_ok  = $answer !== '' && ! $is_stub;
		$ctx->emit_step( [
			'label'  => 'Final answer composed',
			'status' => $ans_ok ? 'pass' : 'fail',
			'detail' => $ans_ok
				? sprintf( '%d chars', mb_strlen( $answer ) )
				: ( $is_stub ? 'STUB (LLM synth unavailable)' : 'EMPTY' ),
		] );

		// Step 6: forced_final / error annotation from the row error field.
		$err = (string) ( $row['error'] ?? '' );
		$ctx->emit_step( [
			'label'  => 'Error / forced_final flag',
			'status' => $err === '' ? 'pass' : 'fail',
			'detail' => $err === '' ? 'none' : $err,
		] );

		// Step 7: timing.
		$ctx->emit_step( [
			'label'  => 'Elapsed time',
			'status' => $elapsed_ms < 60000 ? 'pass' : 'fail',
			'detail' => $elapsed_ms . ' ms (budget cap 60000)',
		] );

		// Step 8: sample raw last-iter output (truncated) — gives operator
		// a peek at what the gateway model actually emitted so they can spot
		// prompt drift / format breakage without enabling WP_DEBUG.
		$last = end( $iters );
		if ( is_array( $last ) ) {
			$ctx->emit_step( [
				'label'  => 'Last iter action / thought',
				'status' => 'pass',
				'detail' => sprintf(
					'action=%s · thought=%s',
					(string) ( $last['action'] ?? '?' ),
					mb_substr( (string) ( $last['thought'] ?? '' ), 0, 160 )
				),
			] );
		}

		// Verdict — pass only if we got both results AND a real (non-stub) answer.
		$ok = $results && $ans_ok && $cite_n > 0;
		if ( ! $ok ) {
			$reasons = [];
			if ( ! $results )  $reasons[] = 'no search results';
			if ( ! $ans_ok )   $reasons[] = ( $is_stub ? 'stub answer (LLM down)' : 'empty answer' );
			if ( ! $cite_n )   $reasons[] = 'no [web:N#URL] citations';
			if ( $err !== '' ) $reasons[] = 'flag: ' . $err;

			return [
				'status'   => 'fail',
				'summary'  => 'Web Deep run incomplete — ' . implode( '; ', $reasons ),
				'error'    => implode( '; ', $reasons ),
				'fix_hint' => $is_stub
					? 'Force-final synthesis vẫn rỗng → check gateway LLM health, tăng FORCE_FINAL_TIMEOUT_S, hoặc kiểm tra retry-with-simpler-prompt path trong force_final_synthesis().'
					: ( ! $cite_n
						? 'LLM không emit [web:N#URL] tokens → prompt drift; thử bumped MAX_ITERATIONS hoặc kiểm tra system prompt template.'
						: 'Xem chi tiết từng step + error flag để xác định root cause.' ),
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf(
				'Web Deep OK — %d iters · %d results · %d citations · %dms · %dchars answer',
				count( $iters ), count( $results ), $cite_n, $elapsed_ms, mb_strlen( $answer )
			),
		];
	}

	public function cleanup(): void {
		// Real-call probe; no temp state to clean up. Gateway usage rows
		// persist intentionally for cost tracking.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Web_Deep_LLM';
	return $list;
} );
