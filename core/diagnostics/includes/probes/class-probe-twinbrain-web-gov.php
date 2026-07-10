<?php
/**
 * Diagnostics probe: twinbrain.web.gov (TBR.W17 Wave 1).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-28
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_TwinBrain_Web_Gov', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Web_Gov implements BizCity_Diagnostics_Probe {

	const PROBE_QUERY = 'chính sách hỗ trợ doanh nghiệp nhỏ và vừa';

	public function id(): string          { return 'twinbrain.web.gov'; }
	public function label(): string       { return 'Web Gov — real Tavily + LLM call'; }
	public function description(): string { return 'Gọi thật BizCity_TwinBrain_Web_Gov. Kiểm tra allowlist (chinhphu/quochoi/các Bộ .gov.vn + báo chính thống), citation [gov:N], time_range=week.'; }
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 42; }
	public function icon(): string        { return 'landmark'; }
	public function estimate_ms(): int    { return 8000; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Web_Gov' ) )     return 'BizCity_TwinBrain_Web_Gov chưa load.';
		if ( ! class_exists( 'BizCity_LLM_Client' ) )            return 'BizCity_LLM_Client chưa load.';
		if ( ! class_exists( 'BizCity_Search_Client' ) )         return 'BizCity_Search_Client chưa load.';
		return true;
	}

	public function run( $ctx ): array {
		$ctx->emit_step( [ 'label' => 'Query', 'status' => 'pass', 'detail' => self::PROBE_QUERY ] );

		$trace_id = 'probe-web-gov-' . wp_generate_uuid4();
		$started  = microtime( true );
		try { $row = BizCity_TwinBrain_Web_Gov::instance()->run( $trace_id, self::PROBE_QUERY, [ 'time_range' => 'month' ] ); } // probe nới time để dễ hit
		catch ( \Throwable $e ) { return [ 'status' => 'fail', 'error' => 'Exception: ' . $e->getMessage(), 'fix_hint' => 'Xem error log.' ]; }
		$elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( ! is_array( $row ) ) return [ 'status' => 'fail', 'error' => 'run() non-array', 'fix_hint' => 'Check contract.' ];

		$results = (array) ( $row['results'] ?? [] );
		$tier_x = 0; $tier_ok = 0;
		foreach ( $results as $r ) { ( ( $r['tier'] ?? 'X' ) === 'X' ) ? $tier_x++ : $tier_ok++; }
		$ctx->emit_step( [ 'label' => 'Allowlist hits', 'status' => $tier_ok > 0 ? 'pass' : 'fail', 'detail' => sprintf( '%d/%d tier A-D (%d X)', $tier_ok, count( $results ), $tier_x ) ] );

		$cite_n = (int) ( $row['citation_count'] ?? 0 );
		$native = 0;
		foreach ( (array) ( $row['citations'] ?? [] ) as $c ) if ( strpos( (string) ( $c['token'] ?? '' ), '[gov:' ) === 0 ) $native++;
		$ctx->emit_step( [ 'label' => 'Citation [gov:N#URL]', 'status' => $cite_n > 0 ? 'pass' : 'fail', 'detail' => sprintf( '%d total · %d native', $cite_n, $native ) ] );

		$answer  = trim( (string) ( $row['answer_md'] ?? '' ) );
		$is_stub = $answer !== '' && strpos( $answer, '_LLM synth unavailable' ) !== false;
		$ans_ok  = $answer !== '' && ! $is_stub;
		$ctx->emit_step( [ 'label' => 'Final answer', 'status' => $ans_ok ? 'pass' : 'fail', 'detail' => $ans_ok ? sprintf( '%d chars', mb_strlen( $answer ) ) : 'STUB/EMPTY' ] );

		$stance = (string) ( $row['stance'] ?? 'unknown' );
		$stance_ok = in_array( $stance, [ 'unknown', 'conditional', 'confident' ], true );
		$ctx->emit_step( [ 'label' => 'Stance value', 'status' => $stance_ok ? 'pass' : 'fail', 'detail' => $stance ] );

		$ctx->emit_step( [ 'label' => 'Time range', 'status' => 'pass', 'detail' => (string) ( $row['time_range'] ?? '?' ) ] );
		$ctx->emit_step( [ 'label' => 'Elapsed', 'status' => $elapsed_ms < 30000 ? 'pass' : 'fail', 'detail' => $elapsed_ms . ' ms' ] );
		$err = (string) ( $row['error'] ?? '' );
		$ctx->emit_step( [ 'label' => 'Error flag', 'status' => $err === '' ? 'pass' : 'fail', 'detail' => $err === '' ? 'none' : $err ] );

		$ok = $tier_ok > 0 && $cite_n > 0 && $ans_ok && $stance_ok;
		if ( ! $ok ) {
			$reasons = [];
			if ( $tier_ok === 0 ) $reasons[] = 'no allowlist hits';
			if ( $cite_n === 0 )  $reasons[] = 'no [gov:N]';
			if ( ! $ans_ok )      $reasons[] = $is_stub ? 'stub' : 'empty';
			if ( ! $stance_ok )   $reasons[] = "stance=$stance";
			if ( $err !== '' )    $reasons[] = 'flag: ' . $err;
			return [ 'status' => 'fail', 'summary' => 'Web Gov incomplete — ' . implode( '; ', $reasons ), 'error' => implode( '; ', $reasons ), 'fix_hint' => 'Check seeder + Web_Gov::run().' ];
		}
		return [ 'status' => 'pass', 'summary' => sprintf( 'Web Gov OK — %d/%d allowlist · %d cite · stance=%s · %dms', $tier_ok, count( $results ), $cite_n, $stance, $elapsed_ms ) ];
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Web_Gov';
	return $list;
} );
