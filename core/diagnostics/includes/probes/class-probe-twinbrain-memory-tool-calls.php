<?php
/**
 * BizCity Diagnostics — twinbrain.memory.tool-calls probe
 * (Wave 2.8 TBR.MEM-6).
 *
 * Real-call probe cho Mode 3 MemGPT-style function-call tools. Plant một
 * synthetic final_text chứa 3 tool block (remember + forget + recall) →
 * `BizCity_TwinBrain_Memory_Tool_Dispatcher::dispatch_from_text()` →
 * verify ops count, persisted/forgotten/recalled counters, rewritten text
 * có chip `[mem:U#<id>]` + italic recall note + đã strip raw tool blocks.
 *
 * Cleanup xoá mọi memory_users row có sentinel.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-24 (Phase 0.36-UNIFIED Wave 2.8 TBR.MEM-6)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_TwinBrain_Memory_Tool_Calls implements BizCity_Diagnostics_Probe {

	const SENTINEL = '__healthtest_tool_token_kookaburra51';

	public function id(): string          { return 'twinbrain.memory.tool-calls'; }
	public function label(): string       { return 'TwinBrain Memory Tool Dispatcher (Mode 3)'; }
	public function description(): string {
		return 'Plant final_text chứa <tool name="memory_remember/forget/recall"> → dispatcher parse + execute → verify 3 ops + rewritten text có [mem:U#<id>] chip. Tool registration đi qua filter `bizcity_twin_register_tool`.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int       { return 64; }
	public function icon(): string     { return 'tools'; }
	public function estimate_ms(): int { return 400; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Memory_Tool_Dispatcher' ) ) {
			return 'Dispatcher class chưa load — kiểm tra core/twinbrain/bootstrap.php.';
		}
		if ( ! class_exists( 'BizCity_Twin_Tool_Registry' ) ) {
			return 'Tool Registry chưa load.';
		}
		if ( get_current_user_id() <= 0 ) {
			return 'Probe cần admin login (memory_* tools gate user_id).';
		}
		// Verify 3 tool đã đăng ký qua filter.
		$reg = BizCity_Twin_Tool_Registry::instance();
		$missing = [];
		foreach ( [ 'memory_remember', 'memory_forget', 'memory_recall' ] as $name ) {
			if ( ! $reg->get( $name ) ) $missing[] = $name;
		}
		if ( ! empty( $missing ) ) {
			return 'Tool chưa đăng ký: ' . implode( ', ', $missing ) . ' — check add_filter(bizcity_twin_register_tool).';
		}
		return true;
	}

	public function run( $ctx ): array {
		$this->cleanup();

		$user_id  = get_current_user_id();
		$trace_id = 'probe-mem-tool-' . wp_generate_uuid4();
		$sentinel = self::SENTINEL;

		// Step 0 — seed một memory để memory_forget có cái xoá.
		$mem_table = $GLOBALS['wpdb']->prefix . 'bizcity_memory_users';
		$blog_id   = get_current_blog_id();
		$seed_text = $sentinel . ' seed forget candidate';
		BizCity_User_Memory::instance()->upsert_public( [
			'user_id'     => $user_id,
			'session_id'  => '',
			'memory_tier' => 'explicit',
			'memory_type' => 'fact',
			'memory_key'  => 'probe:tool:' . md5( $seed_text ),
			'memory_text' => $seed_text,
			'score'       => 90,
			'metadata'    => wp_json_encode( [ 'source' => 'probe.twinbrain.memory.tool-calls', 'trace_id' => $trace_id ] ),
		] );

		// Build synthetic final_text giả lập LLM emit 3 tool inline.
		$final_text = "Đây là câu trả lời mẫu cho diagnostics.\n\n"
		            . "Tôi sẽ ghi nhớ thông tin này: "
		            . '<tool name="memory_remember">' . wp_json_encode( [
		                  'text'  => $sentinel . ' — codename probe Mode 3, không phải sản phẩm thật.',
		                  'type'  => 'preference',
		                  'score' => 85,
		              ] ) . '</tool>'
		            . "\n\nDọn dẹp memory cũ: "
		            . '<tool name="memory_forget">' . wp_json_encode( [
		                  'match_text' => $sentinel . ' seed forget',
		                  'reason'     => 'probe cleanup',
		              ] ) . '</tool>'
		            . "\n\nLet me also recall: "
		            . '<tool name="memory_recall">' . wp_json_encode( [
		                  'query' => $sentinel,
		                  'top_k' => 5,
		              ] ) . '</tool>'
		            . "\n\nKết thúc.";

		$events = [];
		try {
			$disp = BizCity_TwinBrain_Memory_Tool_Dispatcher::instance()->dispatch_from_text(
				$final_text,
				[
					'trace_id'   => $trace_id,
					'user_id'    => $user_id,
					'session_id' => '',
					'surface'    => 'diagnostics',
					'on_event'   => function ( $k, $p ) use ( &$events ) {
						$events[] = [ 'k' => $k, 'tool' => $p['tool'] ?? '', 'ok' => $p['ok'] ?? null ];
					},
				]
			);
		} catch ( \Throwable $e ) {
			return [ 'status' => 'fail', 'error' => 'Exception in dispatcher: ' . $e->getMessage() ];
		}

		$tool_calls = (int) ( $disp['tool_calls'] ?? 0 );
		$persisted  = (int) ( $disp['persisted']  ?? 0 );
		$forgotten  = (int) ( $disp['forgotten']  ?? 0 );
		$recalled   = (int) ( $disp['recalled']   ?? 0 );
		$errors     = (array)( $disp['errors']    ?? [] );
		$rewritten  = (string)( $disp['rewritten_text'] ?? '' );

		$ctx->emit_step( [
			'label'  => 'Dispatcher counters',
			'status' => ( $tool_calls === 3 && $persisted === 1 && $forgotten === 1 && $recalled === 1 ) ? 'pass' : 'fail',
			'detail' => sprintf( 'tool_calls=%d · persisted=%d · forgotten=%d · recalled=%d · errors=%d · %dms',
				$tool_calls, $persisted, $forgotten, $recalled, count( $errors ), (int) ( $disp['latency_ms'] ?? 0 ) ),
		] );

		$ctx->emit_step( [
			'label'  => 'SSE events emitted',
			'status' => count( $events ) >= 6 ? 'pass' : 'fail', // 3 call + 3 result min
			'detail' => count( $events ) . ' events: ' . implode( ',', array_unique( array_column( $events, 'k' ) ) ),
		] );

		$has_chip   = ( strpos( $rewritten, '[mem:U#' ) !== false );
		$has_recall = ( strpos( $rewritten, '🔍' ) !== false );
		$has_forget = ( stripos( $rewritten, 'đã quên' ) !== false );
		$stripped   = ( strpos( $rewritten, '<tool' ) === false );

		$ctx->emit_step( [
			'label'  => 'Rewritten text contract',
			'status' => ( $has_chip && $has_recall && $has_forget && $stripped ) ? 'pass' : 'fail',
			'detail' => sprintf( 'chip=%s · recall=%s · forget=%s · stripped=%s',
				$has_chip ? 'y' : 'n', $has_recall ? 'y' : 'n', $has_forget ? 'y' : 'n', $stripped ? 'y' : 'n' ),
		] );

		// Verify DB row for memory_remember exists.
		global $wpdb;
		$persisted_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$mem_table} WHERE blog_id = %d AND user_id = %d AND memory_text LIKE %s",
			$blog_id, $user_id, '%' . $wpdb->esc_like( $sentinel ) . '%codename probe Mode 3%'
		) );
		$ctx->emit_step( [
			'label'  => 'DB: remember row inserted',
			'status' => $persisted_count >= 1 ? 'pass' : 'fail',
			'detail' => $persisted_count . ' rows match',
		] );

		// Verify seed forget row gone.
		$seed_remaining = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$mem_table} WHERE blog_id = %d AND user_id = %d AND memory_text LIKE %s",
			$blog_id, $user_id, '%' . $wpdb->esc_like( $sentinel . ' seed forget' ) . '%'
		) );
		$ctx->emit_step( [
			'label'  => 'DB: forget removed seed row',
			'status' => $seed_remaining === 0 ? 'pass' : 'fail',
			'detail' => $seed_remaining . ' seed rows remain',
		] );

		$all_ok = ( $tool_calls === 3 && $persisted === 1 && $forgotten === 1 && $recalled === 1
		          && $has_chip && $has_recall && $has_forget && $stripped
		          && $persisted_count >= 1 && $seed_remaining === 0 );

		if ( ! $all_ok ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Mode 3 tool dispatcher contract vi phạm.',
				'error'    => 'errors=' . implode( '|', $errors ),
				'fix_hint' => 'Check dispatcher::find_all_blocks regex, render_replacement(), và filter bizcity_twin_register_tool đăng ký đúng 3 instance.',
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf( 'Mode 3 OK — 3 tool calls, 1 chip injected, %d SSE events, %dms', count( $events ), (int) $disp['latency_ms'] ),
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
	$list[] = 'BizCity_Probe_TwinBrain_Memory_Tool_Calls';
	return $list;
} );
