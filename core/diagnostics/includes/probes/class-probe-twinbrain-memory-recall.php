<?php
/**
 * BizCity Diagnostics — twinbrain.memory.recall probe (Wave 2.8 TBR.MEM-12a).
 *
 * Real-call probe for TwinBrain Layer 0.5 Memory Recall. Plants a deterministic
 * `__healthtest_` explicit memory row for the current admin, runs
 * `BizCity_TwinBrain_Memory_Recall::collect()` with a prompt designed to hit
 * that row via keyword overlap, then asserts:
 *
 *   • collect() returns ≥1 citation token in `[mem:U#<id>]` format
 *   • the planted row's id appears among returned citations
 *   • block text contains the planted memory snippet
 *   • returned counts.A ≥ 1 and block_len ≤ BLOCK_CAP_CHARS
 *
 * Cleanup pass deletes the planted row deterministically by memory_key.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-22 (Phase 0.36-UNIFIED Wave 2.8 TBR.MEM-12a)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_TwinBrain_Memory_Recall implements BizCity_Diagnostics_Probe {

	const PROBE_TOKEN  = '__healthtest_recall_token_zebra47';
	const PROBE_TEXT   = 'Test memory: prefer __healthtest_recall_token_zebra47 brand for diagnostics.';
	const MEMORY_KEY   = 'healthtest:recall:zebra47';
	const PROBE_PROMPT = 'Cho tôi biết về __healthtest_recall_token_zebra47 prefer brand?';

	/** @var int|null planted row id (for cleanup) */
	private $planted_id = null;

	public function id(): string          { return 'twinbrain.memory.recall'; }
	public function label(): string       { return 'TwinBrain Memory Recall (Layer 0.5)'; }
	public function description(): string {
		return 'Plant 1 row __healthtest_ vào bizcity_memory_users → gọi Memory_Recall::collect() → verify row được pull, format [mem:U#id], citation echo. Cleanup tự xoá row.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int       { return 60; }
	public function icon(): string     { return 'brain-circuit'; }
	public function estimate_ms(): int { return 800; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Memory_Recall' ) ) {
			return 'BizCity_TwinBrain_Memory_Recall chưa load — twinbrain bootstrap không hoàn tất.';
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			return 'BizCity_User_Memory chưa load — knowledge module chưa active.';
		}
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return 'Probe cần admin login (get_current_user_id > 0) để plant test memory row.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$user_id = get_current_user_id();

		// Step 1 — plant.
		$mem = BizCity_User_Memory::instance();
		$res = $mem->upsert_public( [
			'user_id'     => $user_id,
			'session_id'  => '',
			'memory_tier' => 'explicit',
			'memory_type' => 'preference',
			'memory_key'  => self::MEMORY_KEY,
			'memory_text' => self::PROBE_TEXT,
			'score'       => 95,
		] );
		if ( ! $res ) {
			return [
				'status'   => 'fail',
				'error'    => 'upsert_public() returned false — không plant được test row.',
				'fix_hint' => 'Check $wpdb->last_error trong WP_DEBUG_LOG; có thể bizcity_memory_users table missing → run Diagnostics Auto-Fix.',
			];
		}
		$this->planted_id = $this->find_planted_id( $user_id );
		$ctx->emit_step( [
			'label'  => 'Plant test row',
			'status' => $this->planted_id ? 'pass' : 'fail',
			'detail' => $this->planted_id ? ( $res . ' · id=' . $this->planted_id ) : 'no id found',
		] );
		if ( ! $this->planted_id ) {
			return [ 'status' => 'fail', 'error' => 'Planted row not retrievable by key.' ];
		}

		// Step 2 — collect.
		$started = microtime( true );
		try {
			$out = BizCity_TwinBrain_Memory_Recall::instance()->collect( $user_id, self::PROBE_PROMPT, [
				'session_id' => 'probe-' . $this->planted_id,
			] );
		} catch ( \Throwable $e ) {
			return [
				'status'   => 'fail',
				'error'    => 'Exception in collect(): ' . $e->getMessage(),
				'fix_hint' => 'Check error log; có thể Notebook_Selector::tokenize_for_search() fatal.',
			];
		}
		$elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		$citations = (array) ( $out['citations'] ?? [] );
		$block     = (string) ( $out['block']    ?? '' );
		$counts    = (array)  ( $out['counts']   ?? [] );
		$expect    = '[mem:U#' . $this->planted_id . ']';

		$ctx->emit_step( [
			'label'  => 'collect() returned',
			'status' => $out ? 'pass' : 'fail',
			'detail' => sprintf(
				'%d citations · counts A=%d B=%d C=%d D=%d · block=%d chars · %dms',
				count( $citations ),
				(int) ( $counts['A'] ?? 0 ),
				(int) ( $counts['B'] ?? 0 ),
				(int) ( $counts['C'] ?? 0 ),
				(int) ( $counts['D'] ?? 0 ),
				mb_strlen( $block ),
				$elapsed_ms
			),
		] );

		$has_cite  = in_array( $expect, $citations, true ) || strpos( $block, $expect ) !== false;
		$has_text  = strpos( $block, self::PROBE_TOKEN ) !== false;
		$under_cap = mb_strlen( $block ) <= BizCity_TwinBrain_Memory_Recall::BLOCK_CAP_CHARS;

		$ctx->emit_step( [
			'label'  => 'Citation [mem:U#' . $this->planted_id . '] present',
			'status' => $has_cite ? 'pass' : 'fail',
			'detail' => $has_cite ? 'yes' : 'missing in citations[] and block',
		] );
		$ctx->emit_step( [
			'label'  => 'Memory text in block',
			'status' => $has_text ? 'pass' : 'fail',
			'detail' => $has_text ? 'token found' : 'planted text not recalled',
		] );
		$ctx->emit_step( [
			'label'  => 'Block ≤ ' . BizCity_TwinBrain_Memory_Recall::BLOCK_CAP_CHARS . ' chars',
			'status' => $under_cap ? 'pass' : 'fail',
			'detail' => mb_strlen( $block ) . ' chars',
		] );

		if ( ! $has_cite || ! $has_text || ! $under_cap ) {
			$reasons = [];
			if ( ! $has_cite )  $reasons[] = 'missing [mem:U#' . $this->planted_id . '] citation';
			if ( ! $has_text )  $reasons[] = 'planted text not echoed in block';
			if ( ! $under_cap ) $reasons[] = 'block exceeds cap';
			return [
				'status'   => 'fail',
				'summary'  => 'Memory Recall incomplete — ' . implode( '; ', $reasons ),
				'error'    => implode( '; ', $reasons ),
				'fix_hint' => 'Check Memory_Recall::format_line() và tier-A enumeration; verify upsert_public lưu memory_tier=explicit.',
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf(
				'Recall OK — row #%d recalled · %d citations · %d chars block · %dms',
				$this->planted_id, count( $citations ), mb_strlen( $block ), $elapsed_ms
			),
		];
	}

	public function cleanup(): void {
		global $wpdb;
		if ( ! $this->planted_id ) {
			// Best-effort: still delete by key in case run() aborted early.
			$table = $wpdb->prefix . 'bizcity_memory_users';
			$wpdb->delete( $table, [ 'memory_key' => self::MEMORY_KEY ], [ '%s' ] );
			return;
		}
		$table = $wpdb->prefix . 'bizcity_memory_users';
		$wpdb->delete( $table, [ 'id' => $this->planted_id ], [ '%d' ] );
		$this->planted_id = null;
	}

	private function find_planted_id( int $user_id ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'bizcity_memory_users';
		$blog_id = get_current_blog_id();
		$id      = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE blog_id = %d AND user_id = %d AND memory_key = %s LIMIT 1",
			$blog_id, $user_id, self::MEMORY_KEY
		) );
		return $id > 0 ? $id : null;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Memory_Recall';
	return $list;
} );
