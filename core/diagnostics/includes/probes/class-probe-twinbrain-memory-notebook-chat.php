<?php
/**
 * BizCity Diagnostics — twinbrain.memory.notebook-chat probe
 * (Wave 2.8b TBR.MEM-N5, 2026-05-23).
 *
 * Parity probe: kiểm chứng notebook chat (Layer 0.5 Recall + Layer 4.7 Writer)
 * dùng cùng singleton + cùng schema event như master Ask Brain. Probe này
 * KHÔNG drive HTTP SSE stream (sẽ nặng + cần auth nonce). Thay vào đó nó:
 *
 *   1. Plant 1 row __healthtest_ vào bizcity_memory_users (mimic prior turn).
 *   2. Gọi Memory_Recall::collect() với opts surface='twinchat-notebook' +
 *      notebook_id giả → assert recall pull row planted (counts.A ≥ 1).
 *   3. Gọi Memory_Writer::extract_and_persist() với prompt "hãy nhớ ..." +
 *      opts surface='twinchat-notebook' → assert row mới persisted.
 *   4. Cleanup tất cả sentinel rows.
 *
 * Mục đích: chốt rằng 2 singleton accept opts notebook-scope không throw +
 * trả output đúng schema cho FE reducer dùng chung event taxonomy.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-23 (Phase 0.36-UNIFIED Wave 2.8b TBR.MEM-N5)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_TwinBrain_Memory_Notebook_Chat', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Memory_Notebook_Chat implements BizCity_Diagnostics_Probe {

	const SENTINEL      = '__healthtest_nbchat_token_kookaburra21';
	const PROBE_NB_ID   = 99001; // synthetic notebook id — không cần tồn tại thật.
	const RECALL_PROMPT = 'Cho tôi biết về __healthtest_nbchat_token_kookaburra21 trong notebook hiện tại?';
	const WRITE_PROMPT  = 'Hãy nhớ rằng __healthtest_nbchat_token_kookaburra21 là codename notebook chat parity diagnostics.';

	public function id(): string          { return 'twinbrain.memory.notebook-chat'; }
	public function label(): string       { return 'TwinBrain Memory — Notebook chat parity'; }
	public function description(): string {
		return 'Verify Memory_Recall + Memory_Writer accept opts surface=twinchat-notebook + notebook_id (Wave 2.8b parity). Plant row → recall → write → cleanup.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int       { return 63; }
	public function icon(): string     { return 'notebook-tabs'; }
	public function estimate_ms(): int { return 1200; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Memory_Recall' ) ) {
			return 'BizCity_TwinBrain_Memory_Recall chưa load.';
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Memory_Writer' ) ) {
			return 'BizCity_TwinBrain_Memory_Writer chưa load.';
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			return 'BizCity_User_Memory chưa load.';
		}
		if ( get_current_user_id() <= 0 ) {
			return 'Probe cần admin login (get_current_user_id > 0).';
		}
		return true;
	}

	public function run( $ctx ): array {
		$user_id = get_current_user_id();
		$this->cleanup();

		// Step 1 — plant explicit memory như turn trước đã ghi nhớ.
		$mem    = BizCity_User_Memory::instance();
		$plant_ok = false;
		try {
			$plant_ok = (bool) $mem->upsert_public( [
				'user_id'     => $user_id,
				'memory_tier' => 'explicit',
				'memory_type' => 'preference',
				'memory_key'  => 'healthtest:nbchat:kookaburra21',
				'memory_text' => 'Test preference: prefer ' . self::SENTINEL . ' brand for notebook chat parity diagnostics.',
				'score'       => 90,
				'source'      => 'diagnostics_probe',
			] );
		} catch ( \Throwable $e ) {
			return [ 'status' => 'fail', 'error' => 'Plant row failed: ' . $e->getMessage() ];
		}
		$ctx->emit_step( [
			'label'  => 'Plant explicit memory row',
			'status' => $plant_ok ? 'pass' : 'fail',
			'detail' => $plant_ok ? 'sentinel inserted' : 'upsert_public returned false',
		] );
		if ( ! $plant_ok ) {
			return [ 'status' => 'fail', 'error' => 'BizCity_User_Memory::upsert_public() failed' ];
		}

		// Step 2 — Memory_Recall.collect() với notebook surface opts.
		try {
			$rec = BizCity_TwinBrain_Memory_Recall::instance()->collect(
				$user_id,
				self::RECALL_PROMPT,
				[
					'notebook_id' => self::PROBE_NB_ID,
					'session_id'  => 'probe-nbchat-' . wp_generate_uuid4(),
					'surface'     => 'twinchat-notebook',
				]
			);
		} catch ( \Throwable $e ) {
			$this->cleanup();
			return [ 'status' => 'fail', 'error' => 'Recall threw: ' . $e->getMessage() ];
		}
		$counts_a   = (int) ( $rec['counts']['A'] ?? 0 );
		$block_len  = (int) mb_strlen( (string) ( $rec['block'] ?? '' ) );
		$cite_count = is_array( $rec['citations'] ?? null ) ? count( $rec['citations'] ) : 0;
		$recall_ok  = $counts_a >= 1 && $block_len > 0;
		$ctx->emit_step( [
			'label'  => 'Memory_Recall(surface=twinchat-notebook)',
			'status' => $recall_ok ? 'pass' : 'fail',
			'detail' => sprintf( 'counts.A=%d · cites=%d · block=%dch · %dms',
				$counts_a, $cite_count, $block_len, (int) ( $rec['latency_ms'] ?? 0 ) ),
		] );
		if ( ! $recall_ok ) {
			$this->cleanup();
			return [
				'status'   => 'fail',
				'summary'  => 'Recall không pull được row đã plant.',
				'fix_hint' => 'Check Memory_Recall::collect() có honor user_id current + tier explicit. Có thể keyword overlap không match — verify tokenize_for_search trên SENTINEL.',
			];
		}

		// Step 3 — Memory_Writer.extract_and_persist() với notebook surface opts.
		$trace_id = 'probe-nbchat-writer-' . wp_generate_uuid4();
		try {
			$mw = BizCity_TwinBrain_Memory_Writer::instance()->extract_and_persist(
				$trace_id,
				self::WRITE_PROMPT,
				'(probe assistant answer)',
				[
					'user_id'     => $user_id,
					'session_id'  => '',
					'notebook_id' => self::PROBE_NB_ID,
					'surface'     => 'twinchat-notebook',
					'enable_llm'  => false,
				]
			);
		} catch ( \Throwable $e ) {
			$this->cleanup();
			return [ 'status' => 'fail', 'error' => 'Writer threw: ' . $e->getMessage() ];
		}
		$persisted = (int) ( $mw['persisted'] ?? 0 );
		$mode      = (string) ( $mw['mode'] ?? '' );
		$write_ok  = $persisted >= 1;
		$ctx->emit_step( [
			'label'  => 'Memory_Writer(surface=twinchat-notebook)',
			'status' => $write_ok ? 'pass' : 'fail',
			'detail' => sprintf( 'mode=%s · persisted=%d · %dms',
				$mode, $persisted, (int) ( $mw['latency_ms'] ?? 0 ) ),
		] );

		$this->cleanup();

		if ( ! $write_ok ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Writer Mode 1 không persist được row.',
				'fix_hint' => 'Check regex "hãy nhớ" trong extract_explicit() — prompt mẫu phải match.',
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf( 'Notebook-chat parity OK — recall A=%d cites · writer persisted=%d', $counts_a, $persisted ),
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
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Memory_Notebook_Chat';
	return $list;
} );
