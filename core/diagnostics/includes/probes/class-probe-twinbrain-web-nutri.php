<?php
/**
 * Diagnostics probe: twinbrain.web.nutri (TBR.W17 Wave 1).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-28
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_TwinBrain_Web_Nutri', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Web_Nutri implements BizCity_Diagnostics_Probe {

	const PROBE_QUERY = 'protein hằng ngày cho người trưởng thành';

	public function id(): string          { return 'twinbrain.web.nutri'; }
	public function label(): string       { return 'Web Nutri — real Tavily + LLM call'; }
	public function description(): string { return 'Gọi thật BizCity_TwinBrain_Web_Nutri. Kiểm tra allowlist (Harvard/WHO/FAO/NIH/USDA/Viện Dinh Dưỡng VN), citation [nut:N], disclaimer 🥗.'; }
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 39; }
	public function icon(): string        { return 'apple'; }
	public function estimate_ms(): int    { return 8000; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Web_Nutri' ) ) return 'BizCity_TwinBrain_Web_Nutri chưa load.';
		if ( ! class_exists( 'BizCity_LLM_Client' ) )          return 'BizCity_LLM_Client chưa load.';
		if ( ! class_exists( 'BizCity_Search_Client' ) )       return 'BizCity_Search_Client chưa load.';
		return true;
	}

	public function run( $ctx ): array {
		$ctx->emit_step( [ 'label' => 'Query', 'status' => 'pass', 'detail' => self::PROBE_QUERY ] );

		$trace_id = 'probe-web-nutri-' . wp_generate_uuid4();
		$started  = microtime( true );
		try { $row = BizCity_TwinBrain_Web_Nutri::instance()->run( $trace_id, self::PROBE_QUERY ); }
		catch ( \Throwable $e ) { return [ 'status' => 'fail', 'error' => 'Exception: ' . $e->getMessage(), 'fix_hint' => 'Xem error log.' ]; }
		$elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( ! is_array( $row ) ) return [ 'status' => 'fail', 'error' => 'run() non-array', 'fix_hint' => 'Check contract.' ];

		$results = (array) ( $row['results'] ?? [] );
		$tier_x = 0; $tier_ok = 0;
		foreach ( $results as $r ) { ( ( $r['tier'] ?? 'X' ) === 'X' ) ? $tier_x++ : $tier_ok++; }
		$ctx->emit_step( [ 'label' => 'Allowlist hits', 'status' => $tier_ok > 0 ? 'pass' : 'fail', 'detail' => sprintf( '%d/%d tier A-D (%d X)', $tier_ok, count( $results ), $tier_x ) ] );

		$cite_n     = (int) ( $row['citation_count'] ?? 0 );
		$native     = 0;
		foreach ( (array) ( $row['citations'] ?? [] ) as $c ) if ( strpos( (string) ( $c['token'] ?? '' ), '[nut:' ) === 0 ) $native++;
		$ctx->emit_step( [ 'label' => 'Citation [nut:N#URL]', 'status' => $cite_n > 0 ? 'pass' : 'fail', 'detail' => sprintf( '%d total · %d native', $cite_n, $native ) ] );

		$answer  = trim( (string) ( $row['answer_md'] ?? '' ) );
		$is_stub = $answer !== '' && strpos( $answer, '_LLM synth unavailable' ) !== false;
		$ans_ok  = $answer !== '' && ! $is_stub;
		$has_disc = mb_strpos( $answer, '🥗' ) !== false;
		$ctx->emit_step( [ 'label' => 'Final answer', 'status' => $ans_ok ? 'pass' : 'fail', 'detail' => $ans_ok ? sprintf( '%d chars', mb_strlen( $answer ) ) : 'STUB/EMPTY' ] );
		$ctx->emit_step( [ 'label' => 'Disclaimer (🥗)', 'status' => $has_disc ? 'pass' : 'fail', 'detail' => $has_disc ? ( ! empty( $row['disclaimer_appended'] ) ? 'auto-appended' : 'LLM generated' ) : 'MISSING' ] );

		$stance = (string) ( $row['stance'] ?? 'unknown' );
		$stance_ok = in_array( $stance, [ 'unknown', 'conditional' ], true );
		$ctx->emit_step( [ 'label' => 'Stance cap (≤ conditional)', 'status' => $stance_ok ? 'pass' : 'fail', 'detail' => $stance ] );

		$ctx->emit_step( [ 'label' => 'Elapsed', 'status' => $elapsed_ms < 30000 ? 'pass' : 'fail', 'detail' => $elapsed_ms . ' ms' ] );
		$err = (string) ( $row['error'] ?? '' );
		$ctx->emit_step( [ 'label' => 'Error flag', 'status' => $err === '' ? 'pass' : 'fail', 'detail' => $err === '' ? 'none' : $err ] );

		$ok = $tier_ok > 0 && $cite_n > 0 && $ans_ok && $has_disc && $stance_ok;
		if ( ! $ok ) {
			$reasons = [];
			if ( $tier_ok === 0 ) $reasons[] = 'no allowlist hits';
			if ( $cite_n === 0 )  $reasons[] = 'no [nut:N]';
			if ( ! $ans_ok )      $reasons[] = $is_stub ? 'stub' : 'empty';
			if ( ! $has_disc )    $reasons[] = 'no 🥗 disclaimer';
			if ( ! $stance_ok )   $reasons[] = "stance=$stance";
			if ( $err !== '' )    $reasons[] = 'flag: ' . $err;
			return [ 'status' => 'fail', 'summary' => 'Web Nutri incomplete — ' . implode( '; ', $reasons ), 'error' => implode( '; ', $reasons ), 'fix_hint' => 'Check seeder + ensure_disclaimer().' ];
		}
		return [ 'status' => 'pass', 'summary' => sprintf( 'Web Nutri OK — %d/%d allowlist · %d cite · stance=%s · %dms', $tier_ok, count( $results ), $cite_n, $stance, $elapsed_ms ) ];
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Web_Nutri';
	return $list;
} );
