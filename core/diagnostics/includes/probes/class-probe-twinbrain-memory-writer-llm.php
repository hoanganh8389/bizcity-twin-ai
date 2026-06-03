<?php
/**
 * BizCity Diagnostics — twinbrain.memory.writer.llm probe
 * (Wave 2.8 TBR.MEM-12c — Mode 2 LLM extractor).
 *
 * Real-call probe for TwinBrain Layer 4.7 Memory Writer **Mode 2**. Feeds a
 * deterministic transcript with an implicit identity/preference (no explicit
 * "hãy nhớ" trigger so Mode 1 won't fire) and verifies the LLM extractor
 * persists at least one row tier=extracted via `bizcity_memory_users`.
 *
 * Spends real gateway budget (1 LLM call, purpose=fast, ≤400 tokens out) →
 * marked severity=warning so the Smoke Wizard prompts confirm. Cleanup wipes
 * the planted sentinel rows.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-22 (Phase 0.36-UNIFIED Wave 2.8 TBR.MEM-12c)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_TwinBrain_Memory_Writer_LLM implements BizCity_Diagnostics_Probe {

	const SENTINEL = '__healthtest_llmmem_token_axolotl19';

	/** Implicit pref (Mode 1 regex MUST NOT match) — sentinel embedded as the user's brand pref. */
	const PROBE_PROMPT = "Cuối tuần này mình muốn mua cà phê thủ công. Mình hay uống cà phê hạt arabica của brand __healthtest_llmmem_token_axolotl19 vì độ chua cân bằng, không thích robusta đắng gắt.";
	const PROBE_ANSWER = "Cà phê arabica của brand __healthtest_llmmem_token_axolotl19 đúng là một lựa chọn phổ biến cho người thích vị chua dịu. Bạn có thể thử dạng pour-over để giữ hương thơm tốt nhất.";

	public function id(): string          { return 'twinbrain.memory.writer.llm'; }
	public function label(): string       { return 'TwinBrain Memory Writer — Mode 2 LLM extractor'; }
	public function description(): string {
		return 'Đẩy 1 cặp prompt+answer chứa implicit preference (không có "hãy nhớ") qua extract_and_persist → verify Mode 2 LLM extractor persist ≥1 row tier=extracted. TỐN ~1 LLM call (purpose=fast).';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int       { return 62; }
	public function icon(): string     { return 'sparkles'; }
	public function estimate_ms(): int { return 6000; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Memory_Writer' ) ) {
			return 'BizCity_TwinBrain_Memory_Writer chưa load.';
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			return 'BizCity_User_Memory chưa load.';
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return 'BizCity_LLM_Client chưa load — gateway router chưa active.';
		}
		if ( get_current_user_id() <= 0 ) {
			return 'Probe cần admin login.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$user_id  = get_current_user_id();
		$trace_id = 'probe-mem-llm-' . wp_generate_uuid4();

		// Pre-state — wipe leftovers + bust the 24h dedupe transient.
		$this->cleanup();
		$dedupe = 'bizcity_twb_mw_seen_' . md5( $user_id . '||' . self::PROBE_PROMPT . '|' . self::PROBE_ANSWER );
		delete_transient( $dedupe );

		$ctx->emit_step( [
			'label'  => 'Prompt (implicit pref)',
			'status' => 'pass',
			'detail' => mb_substr( self::PROBE_PROMPT, 0, 90 ) . '…',
		] );

		$writer = BizCity_TwinBrain_Memory_Writer::instance();

		// Mode 1 must NOT fire — sentinel prompt has no "hãy nhớ" / "remember".
		// (If it did, this probe wouldn't isolate Mode 2.)
		try {
			$out = $writer->extract_and_persist( $trace_id, self::PROBE_PROMPT, self::PROBE_ANSWER, [
				'user_id'    => $user_id,
				'session_id' => '',
				'enable_llm' => true,
			] );
		} catch ( \Throwable $e ) {
			return [
				'status'   => 'fail',
				'error'    => 'Exception: ' . $e->getMessage(),
				'fix_hint' => 'Check error log; có thể BizCity_LLM_Client::chat() fatal.',
			];
		}

		$ops        = (array) ( $out['ops'] ?? [] );
		$persisted  = (int)   ( $out['persisted'] ?? 0 );
		$mode       = (string)( $out['mode'] ?? '' );
		$llm_status = (string)( $out['llm_status'] ?? '?' );

		$ctx->emit_step( [
			'label'  => 'extract_and_persist() returned',
			'status' => $out ? 'pass' : 'fail',
			'detail' => sprintf( 'mode=%s · llm_status=%s · ops=%d · persisted=%d · %dms',
				$mode, $llm_status, count( $ops ), $persisted, (int) ( $out['latency_ms'] ?? 0 ) ),
		] );

		// Separate mode1 vs mode2 ops.
		$mode2_ops = array_filter( $ops, function ( $o ) { return ( $o['mode'] ?? '' ) === 'mode2'; } );

		$ctx->emit_step( [
			'label'  => 'Mode 2 LLM status = ok',
			'status' => $llm_status === 'ok' ? 'pass' : 'fail',
			'detail' => $llm_status,
		] );
		$ctx->emit_step( [
			'label'  => 'Mode 2 ops persisted ≥ 1',
			'status' => count( $mode2_ops ) >= 1 ? 'pass' : 'fail',
			'detail' => count( $mode2_ops ) . ' rows',
		] );

		// Verify DB row tier=extracted with sentinel OR a row referencing the trace_id.
		$row = $this->find_extracted_row( $user_id, $trace_id );
		$ctx->emit_step( [
			'label'  => 'DB row visible (tier=extracted)',
			'status' => $row ? 'pass' : 'fail',
			'detail' => $row ? ( '#' . $row->id . ' · type=' . $row->memory_type . ' · text=' . mb_substr( $row->memory_text, 0, 80 ) ) : 'not found',
		] );

		// Verdict.
		if ( $llm_status !== 'ok' ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Mode 2 LLM extractor không chạy được (llm_status=' . $llm_status . ').',
				'error'    => $llm_status,
				'fix_hint' => $this->fix_hint_for_status( $llm_status ),
			];
		}
		if ( count( $mode2_ops ) < 1 || ! $row ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Mode 2 chạy nhưng không persist row nào.',
				'error'    => 'persisted_mode2=' . count( $mode2_ops ) . ' · db_row=' . ( $row ? 'yes' : 'no' ),
				'fix_hint' => 'LLM trả empty memories[] (prompt quá yếu?) hoặc parse_llm_output() reject hết. Bật WP_DEBUG_LOG xem raw LLM output.',
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf(
				'Mode 2 OK — %d row extracted · %dms · row #%d type=%s',
				count( $mode2_ops ), (int) ( $out['latency_ms'] ?? 0 ), $row->id, $row->memory_type
			),
		];
	}

	public function cleanup(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_memory_users';
		// Wipe any rows with our sentinel or our trace prefix in metadata.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE memory_text LIKE %s OR metadata LIKE %s",
			'%' . $wpdb->esc_like( self::SENTINEL ) . '%',
			'%' . $wpdb->esc_like( 'probe-mem-llm-' ) . '%'
		) );
	}

	private function find_extracted_row( int $user_id, string $trace_id ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'bizcity_memory_users';
		$blog_id = get_current_blog_id();
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT id, memory_tier, memory_type, memory_text FROM {$table}
			 WHERE blog_id = %d AND user_id = %d
			   AND memory_tier = 'extracted'
			   AND metadata LIKE %s
			 ORDER BY id DESC LIMIT 1",
			$blog_id, $user_id,
			'%' . $wpdb->esc_like( $trace_id ) . '%'
		) );
	}

	private function fix_hint_for_status( string $status ): string {
		if ( strpos( $status, 'cost_blocked' ) === 0 ) return 'KG_Cost_Guard chặn — check daily cap + per-user quota trong Router settings.';
		if ( $status === 'too_short' )                  return 'prompt+answer < 80 chars — bug logic gate.';
		if ( $status === 'deduped_24h' )                return 'Probe đã chạy trong 24h — đợi hoặc bust transient.';
		if ( $status === 'no_llm_client' )              return 'BizCity_LLM_Client chưa load — bizcity-llm-router plugin chưa active.';
		if ( $status === 'empty_extract' )              return 'LLM trả memories=[] — prompt mẫu không đủ implicit signal, hoặc model không hỗ trợ response_format json.';
		if ( strpos( $status, 'llm_fail' ) === 0 )      return 'Gateway LLM call thất bại — check gateway logs.';
		return 'Xem llm_status để xác định root cause.';
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Memory_Writer_LLM';
	return $list;
} );
