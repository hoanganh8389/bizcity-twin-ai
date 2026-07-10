<?php
/**
 * BizCity Diagnostics — twin.final.compose probe (Phase 0.36-UNIFIED · TBR.W19).
 *
 * Real-call probe for Layer 4.5 Final Composer. Validates the streaming
 * pipeline end-to-end at the BE layer (no FE involvement) by:
 *
 *   1. Building a fake but realistic Synthesizer output (consensus +
 *      tensions + answer_md with [nb:X/pY] tokens).
 *   2. Building a 2-perspective fake $answers array (1 nb + 1 web).
 *   3. Calling `BizCity_TwinBrain_Final_Composer::compose_stream()` with
 *      a counter callback to record `(deltas, total_chars)`.
 *   4. Asserting:
 *        - stream produced ≥ 1 delta (fail otherwise → SSE pipeline broken).
 *        - final answer ≥ 80 chars.
 *        - at least one citation token preserved (`[nb:` OR `[web:`).
 *        - non-empty model identifier returned by gateway.
 *
 * Spends gateway budget (~1 chat_stream call). Marked severity=warning so
 * the Smoke Wizard prompts a confirm before running.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-21 (Phase 0.36-UNIFIED · TBR.W19)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Final_Compose', false ) ) {
	return;
}

final class BizCity_Probe_Final_Compose implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'twin.final.compose'; }
	public function label(): string       { return 'TwinBrain Final Composer (Layer 4.5) streaming'; }
	public function description(): string {
		return 'Gọi thật Final Composer với synthesizer giả + perspectives giả, đo deltas + citation preservation. TỐN ~1 LLM stream call.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 36; }
	public function icon(): string        { return 'sparkles'; }
	public function estimate_ms(): int    { return 12000; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Final_Composer' ) ) {
			return 'BizCity_TwinBrain_Final_Composer chưa load — TwinBrain Wave 2.6 (TBR.W16) chưa shipped.';
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return 'BizCity_LLM_Client chưa load — gateway chưa active.';
		}
		$client = BizCity_LLM_Client::instance();
		if ( method_exists( $client, 'is_ready' ) && ! $client->is_ready() ) {
			return 'Gateway API key chưa cấu hình (Settings → BizCity LLM).';
		}
		if ( ! method_exists( $client, 'chat_stream' ) ) {
			return 'BizCity_LLM_Client::chat_stream() không tồn tại.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$prompt = 'Tóm tắt ngắn gọn: nên dùng nước rửa chén có bọt nhiều hay ít cho da nhạy cảm?';

		$synth = [
			'consensus' => [
				'Nước rửa chén ít bọt thường chứa surfactant nhẹ hơn — phù hợp da nhạy cảm [nb:101/p3].',
				'pH trung tính (6-8) là yếu tố quan trọng hơn lượng bọt [nb:102/p1].',
			],
			'tensions' => [
				'Notebook 101 nói "ít bọt = ít hoá chất", trong khi nguồn web nói lượng bọt không tương quan trực tiếp [web:1#https://example.com/foam].',
			],
			'recommendation' => 'Ưu tiên loại pH trung tính, ít bọt, nhãn "for sensitive skin"; đeo găng khi rửa lâu.',
			'answer_md'      => "**Consensus:** Nước rửa chén ít bọt thường nhẹ hơn cho da [nb:101/p3]. pH trung tính quan trọng hơn lượng bọt [nb:102/p1].\n\n**Tensions:** Notebook 101 cho rằng ít bọt = ít hoá chất, nhưng [web:1#https://example.com/foam] phản bác.",
			'citations'      => [],
		];

		$answers = [
			[
				'mode'        => 'local',
				'label'       => 'NB Da liễu',
				'notebook_id' => 101,
				'stance'      => 'agree',
				'confidence'  => 0.78,
				'answer_md'   => 'Da nhạy cảm nên chọn surfactant nhẹ. Lượng bọt nhiều thường liên quan đến SLS/SLES [nb:101/p3].',
			],
			[
				'mode'           => 'quick',
				'label'          => 'Web Research (Quick)',
				'answer_md'      => 'Một số bài viết cho rằng bọt nhiều không tương quan với độ tẩy mạnh [web:1#https://example.com/foam].',
				'results'        => [
					[
						'url'     => 'https://example.com/foam',
						'title'   => 'Does soap foam matter?',
						'domain'  => 'example.com',
						'snippet' => 'Foam quantity is mostly cosmetic; cleaning power depends on surfactant type and concentration.',
					],
				],
				'citation_count' => 1,
			],
		];

		$ctx->emit_step( [
			'label'  => 'Synthesizer fixture',
			'status' => 'pass',
			'detail' => sprintf( 'consensus=%d, tensions=%d, recommendation=%s, answer_md=%dchars',
				count( $synth['consensus'] ),
				count( $synth['tensions'] ),
				$synth['recommendation'] !== '' ? 'yes' : 'no',
				mb_strlen( $synth['answer_md'] )
			),
		] );

		$trace_id    = 'probe-final-compose-' . wp_generate_uuid4();
		$composer    = BizCity_TwinBrain_Final_Composer::instance();
		$delta_count = 0;
		$last_full   = '';

		$started = microtime( true );
		try {
			$result = $composer->compose_stream(
				$trace_id,
				$prompt,
				$synth,
				$answers,
				[],
				static function ( $delta, $accumulated ) use ( &$delta_count, &$last_full ) {
					$delta_count++;
					$last_full = (string) $accumulated;
				}
			);
		} catch ( \Throwable $e ) {
			return [
				'status'   => 'fail',
				'error'    => 'Exception: ' . $e->getMessage(),
				'fix_hint' => 'Xem error log; có thể chat_stream() ném exception khi parse SSE delta.',
			];
		}
		$elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		$ctx->emit_step( [
			'label'  => 'Stream chunks received',
			'status' => $delta_count > 0 ? 'pass' : 'fail',
			'detail' => $delta_count . ' deltas',
		] );

		$answer = trim( (string) ( $result['answer_md'] ?? '' ) );
		$ctx->emit_step( [
			'label'  => 'Final answer composed',
			'status' => mb_strlen( $answer ) >= 80 ? 'pass' : 'fail',
			'detail' => sprintf( '%d chars (min 80)', mb_strlen( $answer ) ),
		] );

		$nb_cited  = (bool) preg_match( '/\[nb:\d+\/p\d+\]/', $answer );
		$web_cited = (bool) preg_match( '/\[web:\d+#https?:\/\//', $answer );
		$ctx->emit_step( [
			'label'  => 'Citation tokens preserved',
			'status' => ( $nb_cited || $web_cited ) ? 'pass' : 'fail',
			'detail' => sprintf( 'nb=%s, web=%s', $nb_cited ? 'yes' : 'no', $web_cited ? 'yes' : 'no' ),
		] );

		$model = (string) ( $result['model'] ?? '' );
		$ctx->emit_step( [
			'label'  => 'Gateway model identifier',
			'status' => $model !== '' ? 'pass' : 'fail',
			'detail' => $model !== '' ? $model : '(empty)',
		] );

		$fb = (string) ( $result['fallback'] ?? '' );
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
			'label'  => 'Tokens',
			'status' => 'pass',
			'detail' => (int) ( $result['tokens'] ?? 0 ),
		] );

		$ok = $delta_count > 0
			&& mb_strlen( $answer ) >= 80
			&& ( $nb_cited || $web_cited )
			&& $fb === '';

		if ( ! $ok ) {
			$reasons = [];
			if ( $delta_count === 0 )                $reasons[] = 'no stream deltas (SSE broken or LSAPI buffering)';
			if ( mb_strlen( $answer ) < 80 )         $reasons[] = 'final answer too short';
			if ( ! $nb_cited && ! $web_cited )       $reasons[] = 'no citation tokens in output';
			if ( $fb !== '' )                        $reasons[] = 'fallback triggered: ' . $fb;

			return [
				'status'   => 'fail',
				'summary'  => 'Final Composer issue — ' . implode( '; ', $reasons ),
				'error'    => implode( '; ', $reasons ),
				'fix_hint' => $delta_count === 0
					? 'Stream không nhận chunk nào → check gateway SSE endpoint /llm/router/v1/chat/stream + chat_stream() curl_multi loop. Có thể LSAPI đang buffer toàn bộ response.'
					: ( ! $nb_cited && ! $web_cited
						? 'LLM bỏ citation token → kiểm tra system prompt build_messages() rule #4-5; thử bumped temperature hoặc thử model khác.'
						: ( $fb !== '' ? 'Fallback active: gateway error → check API key, model availability, hoặc bumped timeout.' : 'Xem chi tiết step để chẩn đoán.' )
					),
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf(
				'Final Composer OK — %d chunks · %d chars · cite[nb=%s,web=%s] · %dms',
				$delta_count, mb_strlen( $answer ),
				$nb_cited ? 'y' : 'n', $web_cited ? 'y' : 'n', $elapsed_ms
			),
		];
	}

	public function cleanup(): void {
		// Real-call probe; no temp state.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Final_Compose';
	return $list;
} );
