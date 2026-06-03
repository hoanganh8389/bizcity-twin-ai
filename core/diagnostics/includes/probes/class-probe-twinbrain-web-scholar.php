<?php
/**
 * Diagnostics probe: twinbrain.web.scholar (TBR.W17 Wave 1).
 *
 * Real-call BizCity_TwinBrain_Web_Scholar với query học thuật mẫu, kiểm tra
 * allowlist hit (tier A-D), citation `[sch:N]`, stance OK.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-28
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_TwinBrain_Web_Scholar implements BizCity_Diagnostics_Probe {

	const PROBE_QUERY = 'transformer attention mechanism';

	public function id(): string          { return 'twinbrain.web.scholar'; }
	public function label(): string       { return 'Web Scholar — real Tavily + LLM call'; }
	public function description(): string { return 'Gọi thật BizCity_TwinBrain_Web_Scholar với query học thuật mẫu. Kiểm tra allowlist (arxiv/doi/nature/pubmed/ieee/acm…), citation [sch:N], stance OK.'; }
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 38; }
	public function icon(): string        { return 'graduation-cap'; }
	public function estimate_ms(): int    { return 8000; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Web_Scholar' ) ) return 'BizCity_TwinBrain_Web_Scholar chưa load.';
		if ( ! class_exists( 'BizCity_LLM_Client' ) )            return 'BizCity_LLM_Client chưa load.';
		if ( ! class_exists( 'BizCity_Search_Client' ) )         return 'BizCity_Search_Client chưa load.';
		return true;
	}

	public function run( $ctx ): array {
		$ctx->emit_step( [ 'label' => 'Query', 'status' => 'pass', 'detail' => self::PROBE_QUERY ] );

		$trace_id = 'probe-web-scholar-' . wp_generate_uuid4();
		$eng      = BizCity_TwinBrain_Web_Scholar::instance();

		$started = microtime( true );
		try { $row = $eng->run( $trace_id, self::PROBE_QUERY ); }
		catch ( \Throwable $e ) { return [ 'status' => 'fail', 'error' => 'Exception: ' . $e->getMessage(), 'fix_hint' => 'Xem error log; check gateway config.' ]; }
		$elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( ! is_array( $row ) ) return [ 'status' => 'fail', 'error' => 'run() trả về non-array', 'fix_hint' => 'Check Web_Scholar::run() contract.' ];

		$results = (array) ( $row['results'] ?? [] );
		$tier_x = 0; $tier_ok = 0;
		foreach ( $results as $r ) { ( ( $r['tier'] ?? 'X' ) === 'X' ) ? $tier_x++ : $tier_ok++; }
		$ctx->emit_step( [ 'label' => 'Search results in allowlist', 'status' => $tier_ok > 0 ? 'pass' : 'fail', 'detail' => sprintf( '%d/%d in tier A-D (%d out-of-tier)', $tier_ok, count( $results ), $tier_x ) ] );

		$cite_n     = (int) ( $row['citation_count'] ?? 0 );
		$citations  = (array) ( $row['citations'] ?? [] );
		$native     = 0;
		foreach ( $citations as $c ) if ( strpos( (string) ( $c['token'] ?? '' ), '[sch:' ) === 0 ) $native++;
		$ctx->emit_step( [ 'label' => 'Citation tokens [sch:N#URL]', 'status' => $cite_n > 0 ? 'pass' : 'fail', 'detail' => sprintf( '%d total · %d native [sch:]', $cite_n, $native ) ] );

		$answer  = trim( (string) ( $row['answer_md'] ?? '' ) );
		$is_stub = $answer !== '' && strpos( $answer, '_LLM synth unavailable' ) !== false;
		$ans_ok  = $answer !== '' && ! $is_stub;
		$ctx->emit_step( [ 'label' => 'Final answer composed', 'status' => $ans_ok ? 'pass' : 'fail', 'detail' => $ans_ok ? sprintf( '%d chars', mb_strlen( $answer ) ) : ( $is_stub ? 'STUB' : 'EMPTY' ) ] );

		$stance    = (string) ( $row['stance'] ?? 'unknown' );
		$stance_ok = in_array( $stance, [ 'unknown', 'conditional', 'confident' ], true );
		$ctx->emit_step( [ 'label' => 'Stance value', 'status' => $stance_ok ? 'pass' : 'fail', 'detail' => $stance ] );

		$ctx->emit_step( [ 'label' => 'Elapsed time', 'status' => $elapsed_ms < 30000 ? 'pass' : 'fail', 'detail' => $elapsed_ms . ' ms (cap 30000)' ] );
		$err = (string) ( $row['error'] ?? '' );
		$ctx->emit_step( [ 'label' => 'Error flag', 'status' => $err === '' ? 'pass' : 'fail', 'detail' => $err === '' ? 'none' : $err ] );

		$ok = $tier_ok > 0 && $cite_n > 0 && $ans_ok && $stance_ok;
		if ( ! $ok ) {
			$reasons = [];
			if ( $tier_ok === 0 ) $reasons[] = 'no allowlist hits';
			if ( $cite_n === 0 )  $reasons[] = 'no [sch:N] citations';
			if ( ! $ans_ok )      $reasons[] = $is_stub ? 'stub answer' : 'empty answer';
			if ( ! $stance_ok )   $reasons[] = "stance=$stance invalid";
			if ( $err !== '' )    $reasons[] = 'flag: ' . $err;
			return [ 'status' => 'fail', 'summary' => 'Web Scholar incomplete — ' . implode( '; ', $reasons ), 'error' => implode( '; ', $reasons ), 'fix_hint' => $is_stub ? 'LLM gateway down.' : ( $tier_ok === 0 ? 'Tavily rate-limit hoặc query quá hẹp.' : 'Check prompt seeder + Web_Scholar::run().' ) ];
		}
		return [ 'status' => 'pass', 'summary' => sprintf( 'Web Scholar OK — %d/%d allowlist · %d cite · stance=%s · %dms', $tier_ok, count( $results ), $cite_n, $stance, $elapsed_ms ) ];
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Web_Scholar';
	return $list;
} );
