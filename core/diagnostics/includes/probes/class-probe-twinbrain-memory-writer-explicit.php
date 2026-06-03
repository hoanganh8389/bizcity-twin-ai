<?php
/**
 * BizCity Diagnostics — twinbrain.memory.writer.explicit probe
 * (Wave 2.8 TBR.MEM-12b).
 *
 * Real-call probe for TwinBrain Layer 4.7 Memory Writer — Mode 1 (regex
 * extractor on "hãy nhớ..." VN + "remember..." EN). Drives a deterministic
 * prompt through `extract_and_persist()` and asserts a fresh row appears
 * in `bizcity_memory_users` with tier=explicit. Idempotency check re-runs
 * with same trace_id and verifies no duplicate.
 *
 * Cleanup deletes any rows whose memory_key starts with `explicit:` AND
 * memory_text contains the probe sentinel token.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-22 (Phase 0.36-UNIFIED Wave 2.8 TBR.MEM-12b)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_TwinBrain_Memory_Writer_Explicit implements BizCity_Diagnostics_Probe {

	const SENTINEL = '__healthtest_writer_token_quokka83';

	public function id(): string          { return 'twinbrain.memory.writer.explicit'; }
	public function label(): string       { return 'TwinBrain Memory Writer — Mode 1 explicit'; }
	public function description(): string {
		return 'Đẩy prompt "hãy nhớ ..." qua Memory_Writer::extract_and_persist() → verify row mới tier=explicit + idempotent (re-run cùng trace_id không double-insert). Cleanup tự xoá.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int       { return 61; }
	public function icon(): string     { return 'save'; }
	public function estimate_ms(): int { return 600; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Memory_Writer' ) ) {
			return 'BizCity_TwinBrain_Memory_Writer chưa load — twinbrain bootstrap không hoàn tất.';
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			return 'BizCity_User_Memory chưa load.';
		}
		if ( get_current_user_id() <= 0 ) {
			return 'Probe cần admin login.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$user_id = get_current_user_id();
		$prompt  = 'Hãy nhớ giúp tôi rằng ' . self::SENTINEL
		         . ' là codename test diagnostics, không phải sản phẩm thật.';
		$trace_id = 'probe-mem-writer-' . wp_generate_uuid4();

		// Pre-state — wipe any leftover.
		$this->cleanup();

		$writer = BizCity_TwinBrain_Memory_Writer::instance();

		// Step 1 — first run (expect 1 op persisted). enable_llm=false để probe
		// chỉ test Mode 1 (regex) — Mode 2 LLM có probe riêng (writer.llm).
		try {
			$out = $writer->extract_and_persist( $trace_id, $prompt, '', [
				'user_id' => $user_id, 'session_id' => '', 'enable_llm' => false,
			] );
		} catch ( \Throwable $e ) {
			return [ 'status' => 'fail', 'error' => 'Exception in first run: ' . $e->getMessage() ];
		}

		$ops       = (array) ( $out['ops'] ?? [] );
		$persisted = (int)   ( $out['persisted'] ?? 0 );
		$mode      = (string)( $out['mode'] ?? '' );

		$ctx->emit_step( [
			'label'  => 'extract_and_persist() #1',
			'status' => $persisted >= 1 ? 'pass' : 'fail',
			'detail' => sprintf( 'mode=%s · ops=%d · persisted=%d · %dms',
				$mode, count( $ops ), $persisted, (int) ( $out['latency_ms'] ?? 0 ) ),
		] );

		if ( $persisted < 1 ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Writer Mode 1 không persist được row nào.',
				'error'    => 'persisted=0 (mode=' . $mode . ')',
				'fix_hint' => 'Check regex trong Memory_Writer::extract_explicit() — prompt mẫu phải match. Có thể BizCity_User_Memory::upsert_public() không return insert/update.',
			];
		}

		// Step 2 — verify DB row.
		$row = $this->find_sentinel_row( $user_id );
		$ctx->emit_step( [
			'label'  => 'DB row visible (tier=explicit)',
			'status' => $row ? 'pass' : 'fail',
			'detail' => $row ? ( '#' . $row->id . ' · tier=' . $row->memory_tier . ' · type=' . $row->memory_type ) : 'not found',
		] );
		if ( ! $row ) {
			return [
				'status'   => 'fail',
				'error'    => 'upsert reported success nhưng SELECT không thấy row chứa sentinel.',
				'fix_hint' => 'Race condition? Check $wpdb->last_error; verify blog_id filter trong get_memories.',
			];
		}

		// Step 3 — idempotency: re-run with same trace_id should return cached.
		$out2 = $writer->extract_and_persist( $trace_id, $prompt, '', [
			'user_id' => $user_id, 'session_id' => '', 'enable_llm' => false,
		] );
		$is_cached = ( ( $out2['mode'] ?? '' ) === 'cached' ) && ( (int) ( $out2['persisted'] ?? 0 ) === 0 );
		$ctx->emit_step( [
			'label'  => 'Idempotency — same trace_id no-op',
			'status' => $is_cached ? 'pass' : 'fail',
			'detail' => $is_cached ? 'mode=cached · persisted=0' : 'mode=' . ( $out2['mode'] ?? '?' ) . ' · persisted=' . ( $out2['persisted'] ?? '?' ),
		] );

		// Step 4 — verify no duplicate after re-run.
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_memory_users';
		$blog_id = get_current_blog_id();
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE blog_id = %d AND user_id = %d AND memory_text LIKE %s",
			$blog_id, $user_id, '%' . $wpdb->esc_like( self::SENTINEL ) . '%'
		) );
		$ctx->emit_step( [
			'label'  => 'Sentinel row count = 1',
			'status' => $count === 1 ? 'pass' : 'fail',
			'detail' => $count . ' rows',
		] );

		$ok = $row && $is_cached && $count === 1;
		if ( ! $ok ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Writer regressions detected.',
				'fix_hint' => 'Check static $seen map; verify memory_key hash deterministic.',
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf( 'Writer OK — row #%d persisted · idempotent · count=1', $row->id ),
		];
	}

	public function cleanup(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_memory_users';
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE memory_text LIKE %s",
			'%' . $wpdb->esc_like( self::SENTINEL ) . '%'
		) );
	}

	private function find_sentinel_row( int $user_id ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'bizcity_memory_users';
		$blog_id = get_current_blog_id();
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT id, memory_tier, memory_type FROM {$table}
			 WHERE blog_id = %d AND user_id = %d AND memory_text LIKE %s
			 ORDER BY id DESC LIMIT 1",
			$blog_id, $user_id, '%' . $wpdb->esc_like( self::SENTINEL ) . '%'
		) );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Memory_Writer_Explicit';
	return $list;
} );
