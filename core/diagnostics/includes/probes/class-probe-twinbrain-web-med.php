<?php
/**
 * BizCity Diagnostics — twinbrain.web.med probe (TBR.W17 / Vertical Wave 1).
 *
 * Real-call probe cho TwinBrain Web Research **Medical** vertical. Tương tự
 * `class-probe-web-deep-llm.php` nhưng đảm bảo:
 *
 *   • Search results CHỈ rơi vào allowlist tier A-D (pubmed/who/cdc/...).
 *   • Citation tokens dùng namespace `[med:N#URL]` (KHÔNG `[web:N]`).
 *   • Disclaimer y tế `⚕️` xuất hiện trong answer (auto-append hoặc LLM gen).
 *   • stance ≤ 'conditional' (med KHÔNG BAO GIỜ 'confident').
 *
 * Severity = `warning` (giống các probe web khác): phụ thuộc external Tavily
 * + LLM gateway, có thể fail do credit/rate-limit ngoài tầm kiểm soát plugin.
 *
 * Idempotent: trace_id mới mỗi run, không persist side effect ngoài gateway
 * usage logs. Estimate ~7s (1 advanced search + 1 LLM call).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-27 (Phase 0.36-UNIFIED · TBR.W17)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_TwinBrain_Web_Med implements BizCity_Diagnostics_Probe {

	/** Query mẫu — phổ biến, có nhiều nguồn pubmed/who/mayoclinic. */
	const PROBE_QUERY = 'tăng huyết áp ở người lớn nên ăn gì';

	public function id(): string          { return 'twinbrain.web.med'; }
	public function label(): string       { return 'Web Med — real Tavily + LLM call'; }
	public function description(): string {
		return 'Gọi thật BizCity_TwinBrain_Web_Med với query y khoa mẫu. Kiểm tra allowlist hit, citation [med:N], disclaimer ⚕️, stance cap. TỐN budget gateway (~1 search advanced + 1 LLM call).';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 37; } // sau web.deep.llm (35)
	public function icon(): string        { return 'stethoscope'; }
	public function estimate_ms(): int    { return 8000; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Web_Med' ) ) {
			return 'BizCity_TwinBrain_Web_Med class chưa load — TBR.W17 bootstrap không hoàn tất.';
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

		$trace_id = 'probe-web-med-' . wp_generate_uuid4();
		$med      = BizCity_TwinBrain_Web_Med::instance();

		$started = microtime( true );
		try {
			$row = $med->run( $trace_id, self::PROBE_QUERY );
		} catch ( \Throwable $e ) {
			return [
				'status'   => 'fail',
				'error'    => 'Exception: ' . $e->getMessage(),
				'fix_hint' => 'Xem error log WordPress; có thể missing gateway config hoặc fatal trong Web_Med::run().',
			];
		}
		$elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( ! is_array( $row ) ) {
			return [
				'status'   => 'fail',
				'error'    => 'run() trả về non-array (' . gettype( $row ) . ')',
				'fix_hint' => 'Web_Med::run() phải luôn trả về associative array — check contract.',
			];
		}

		// Step 2: search results trong allowlist.
		$results = (array) ( $row['results'] ?? [] );
		$tier_x  = 0; // out-of-tier
		$tier_ok = 0;
		foreach ( $results as $r ) {
			$tier = (string) ( $r['tier'] ?? 'X' );
			if ( $tier === 'X' ) $tier_x++; else $tier_ok++;
		}
		$ctx->emit_step( [
			'label'  => 'Search results in allowlist',
			'status' => $tier_ok > 0 ? 'pass' : 'fail',
			'detail' => sprintf( '%d/%d in tier A-D (%d out-of-tier)', $tier_ok, count( $results ), $tier_x ),
		] );

		// Step 3: citation namespace `[med:N#URL]`.
		$cite_n     = (int) ( $row['citation_count'] ?? 0 );
		$citations  = (array) ( $row['citations'] ?? [] );
		$med_native = 0;
		foreach ( $citations as $c ) {
			$tok = (string) ( $c['token'] ?? '' );
			if ( strpos( $tok, '[med:' ) === 0 ) $med_native++;
		}
		$ctx->emit_step( [
			'label'  => 'Citation tokens [med:N#URL]',
			'status' => $cite_n > 0 ? 'pass' : 'fail',
			'detail' => sprintf( '%d total · %d native [med:]', $cite_n, $med_native ),
		] );

		// Step 4: final answer + disclaimer.
		$answer    = trim( (string) ( $row['answer_md'] ?? '' ) );
		$is_stub   = $answer !== '' && strpos( $answer, '_LLM synth unavailable' ) !== false;
		$has_disc  = mb_strpos( $answer, '⚕️' ) !== false;
		$ans_ok    = $answer !== '' && ! $is_stub;
		$ctx->emit_step( [
			'label'  => 'Final answer composed',
			'status' => $ans_ok ? 'pass' : 'fail',
			'detail' => $ans_ok
				? sprintf( '%d chars', mb_strlen( $answer ) )
				: ( $is_stub ? 'STUB (LLM synth unavailable)' : 'EMPTY' ),
		] );
		$ctx->emit_step( [
			'label'  => 'Medical disclaimer (⚕️)',
			'status' => $has_disc ? 'pass' : 'fail',
			'detail' => $has_disc
				? ( ! empty( $row['disclaimer_appended'] ) ? 'present (auto-appended safety net)' : 'present (LLM generated)' )
				: 'MISSING — safety net failed',
		] );

		// Step 5: stance cap — med KHÔNG BAO GIỜ 'confident'.
		$stance = (string) ( $row['stance'] ?? 'unknown' );
		$stance_ok = in_array( $stance, [ 'unknown', 'conditional' ], true );
		$ctx->emit_step( [
			'label'  => 'Stance cap (≤ conditional)',
			'status' => $stance_ok ? 'pass' : 'fail',
			'detail' => $stance . ( $stance_ok ? '' : ' — VIOLATION (med cap = conditional)' ),
		] );

		// Step 6: severity tag.
		$severity = (string) ( $row['severity'] ?? '' );
		$ctx->emit_step( [
			'label'  => 'Severity estimated',
			'status' => in_array( $severity, [ 'info', 'caution', 'critical' ], true ) ? 'pass' : 'fail',
			'detail' => $severity ?: '(missing)',
		] );

		// Step 7: timing.
		$ctx->emit_step( [
			'label'  => 'Elapsed time',
			'status' => $elapsed_ms < 30000 ? 'pass' : 'fail',
			'detail' => $elapsed_ms . ' ms (budget cap 30000)',
		] );

		// Step 8: error flag.
		$err = (string) ( $row['error'] ?? '' );
		$ctx->emit_step( [
			'label'  => 'Error flag',
			'status' => $err === '' ? 'pass' : 'fail',
			'detail' => $err === '' ? 'none' : $err,
		] );

		// Verdict — pass requires: hits in allowlist + native [med:] citations
		// + non-stub answer + disclaimer + stance cap respected.
		$ok = $tier_ok > 0 && $cite_n > 0 && $ans_ok && $has_disc && $stance_ok;
		if ( ! $ok ) {
			$reasons = [];
			if ( $tier_ok === 0 ) $reasons[] = 'no allowlist hits';
			if ( $cite_n === 0 )  $reasons[] = 'no [med:N] citations';
			if ( ! $ans_ok )      $reasons[] = ( $is_stub ? 'stub answer (LLM down)' : 'empty answer' );
			if ( ! $has_disc )    $reasons[] = 'missing ⚕️ disclaimer';
			if ( ! $stance_ok )   $reasons[] = "stance=$stance violates cap";
			if ( $err !== '' )    $reasons[] = 'flag: ' . $err;

			return [
				'status'   => 'fail',
				'summary'  => 'Web Med run incomplete — ' . implode( '; ', $reasons ),
				'error'    => implode( '; ', $reasons ),
				'fix_hint' => $is_stub
					? 'LLM synth fail → check BizCity_LLM_Client::is_ready() + gateway /llm/chat health.'
					: ( $tier_ok === 0
						? 'No allowlist hits → Tavily có thể bị rate-limit hoặc query quá hẹp. Kiểm tra log gateway + thử search ngoài probe.'
						: ( ! $cite_n
							? 'LLM không emit [med:N#URL] tokens → prompt drift. Check seeder med_prompt_template() đã seed chưa (wp bizcity diag web-skills-seed).'
							: ( ! $has_disc
								? 'Safety net ensure_disclaimer() fail — kiểm tra logic auto-append.'
								: 'Stance cap bị bypass — check Web_Med::run() logic.' ) ) ),
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf(
				'Web Med OK — %d/%d allowlist · %d cite · stance=%s · sev=%s · %dms',
				$tier_ok, count( $results ), $cite_n, $stance, $severity, $elapsed_ms
			),
		];
	}

	public function cleanup(): void {
		// Real-call probe; no temp state to clean up.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Web_Med';
	return $list;
} );
