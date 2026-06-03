<?php
/**
 * TwinBrain — Memory Tool Dispatcher (Wave 2.8 TBR.MEM-6 Mode 3).
 *
 * Post-stream parser cho 3 tool memory (`memory_remember`, `memory_forget`,
 * `memory_recall`). Sau khi Final Composer hoàn tất, runtime gọi
 * dispatch_from_text() — class này:
 *   1. Scan toàn bộ final_text tìm các block `<tool name="memory_*">{...}</tool>`
 *   2. Cap N calls/turn (default 3 — chỉ tính write tool; recall không tính).
 *   3. Mỗi block → dispatch qua `BizCity_Twin_Tool_Registry::get($name)->execute()`.
 *   4. Rewrite block trong final_text:
 *        - memory_remember → `[mem:U#<new_id>]` (chip xuất hiện trong câu trả lời)
 *        - memory_forget   → `_(đã quên: <text>)_`
 *        - memory_recall   → tóm tắt 1 dòng "🔍 Recall: N memory"
 *   5. Trả về { rewritten_text, ops[], persisted, recalled, errors[], latency_ms }.
 *
 * Idempotency: caller (Runtime) gắn trace_id vào context → mỗi tool execution
 * có log riêng. Không cache cross-turn (LLM mỗi turn quyết định lại).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-05-24 (Wave 2.8 TBR.MEM-6)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Memory_Tool_Dispatcher {

	const MAX_WRITE_CALLS_PER_TURN = 3;
	const MAX_RECALL_CALLS_PER_TURN = 5;
	const TOOL_PREFIX               = 'memory_';

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/**
	 * Parse + dispatch all memory tool blocks in $final_text.
	 *
	 * @param string $final_text Final Composer output (raw, có thể chứa tool blocks).
	 * @param array  $ctx { trace_id, user_id, session_id, surface, on_event? callable($event_key,$payload) }
	 * @return array {
	 *   rewritten_text: string,
	 *   ops: array<int,array>,
	 *   persisted: int,
	 *   forgotten: int,
	 *   recalled: int,
	 *   errors: array<int,string>,
	 *   latency_ms: int,
	 *   tool_calls: int,
	 *   skipped: int
	 * }
	 */
	public function dispatch_from_text( string $final_text, array $ctx = [] ): array {
		$t0 = microtime( true );
		$result = [
			'rewritten_text' => $final_text,
			'ops'            => [],
			'persisted'      => 0,
			'forgotten'      => 0,
			'recalled'       => 0,
			'errors'         => [],
			'latency_ms'     => 0,
			'tool_calls'     => 0,
			'skipped'        => 0,
		];

		if ( $final_text === '' || false === strpos( $final_text, '<tool' ) ) {
			$result['latency_ms'] = (int) ( ( microtime( true ) - $t0 ) * 1000 );
			return $result;
		}

		if ( ! class_exists( 'BizCity_Twin_Tool_Registry' ) ) {
			$result['errors'][]   = 'registry_missing';
			$result['latency_ms'] = (int) ( ( microtime( true ) - $t0 ) * 1000 );
			return $result;
		}

		$registry = BizCity_Twin_Tool_Registry::instance();
		$on_event = $ctx['on_event'] ?? null;
		$tool_ctx = [
			'trace_id'   => (string) ( $ctx['trace_id']   ?? '' ),
			'user_id'    => (int)    ( $ctx['user_id']    ?? get_current_user_id() ),
			'session_id' => (string) ( $ctx['session_id'] ?? '' ),
			'surface'    => (string) ( $ctx['surface']    ?? 'twinbrain' ),
		];

		$blocks = $this->find_all_blocks( $final_text );
		if ( empty( $blocks ) ) {
			$result['latency_ms'] = (int) ( ( microtime( true ) - $t0 ) * 1000 );
			return $result;
		}

		$write_count  = 0;
		$recall_count = 0;
		$replacements = []; // raw → replacement (apply in reverse order to preserve offsets)

		foreach ( $blocks as $block ) {
			$name = (string) $block['name'];
			$args = (array)  $block['args'];
			$raw  = (string) $block['raw'];

			// Only handle memory_* tools — leave others alone.
			if ( 0 !== strpos( $name, self::TOOL_PREFIX ) ) {
				continue;
			}

			$result['tool_calls']++;

			// Per-turn caps.
			$is_recall = ( $name === 'memory_recall' );
			if ( $is_recall ) {
				if ( $recall_count >= self::MAX_RECALL_CALLS_PER_TURN ) {
					$result['skipped']++;
					$replacements[] = [ 'raw' => $raw, 'with' => '' ];
					continue;
				}
				$recall_count++;
			} else {
				if ( $write_count >= self::MAX_WRITE_CALLS_PER_TURN ) {
					$result['skipped']++;
					$replacements[] = [ 'raw' => $raw, 'with' => '_(memory tool skipped: cap reached)_' ];
					continue;
				}
				$write_count++;
			}

			$tool = $registry->get( $name );
			if ( ! $tool ) {
				$result['errors'][] = $name . ':not_registered';
				$replacements[]     = [ 'raw' => $raw, 'with' => '' ];
				$this->emit( $on_event, 'memory_tool_error', [
					'trace_id' => $tool_ctx['trace_id'],
					'tool'     => $name,
					'error'    => 'not_registered',
				] );
				continue;
			}

			$this->emit( $on_event, 'memory_tool_call', [
				'trace_id' => $tool_ctx['trace_id'],
				'tool'     => $name,
				'args'     => $args,
			] );

			$call_t0 = microtime( true );
			try {
				$ret = (array) $tool->execute( $args, $tool_ctx );
			} catch ( \Throwable $e ) {
				$ret = [ 'ok' => false, 'error' => 'exception: ' . $e->getMessage() ];
			}
			$call_ms = (int) ( ( microtime( true ) - $call_t0 ) * 1000 );

			$ok      = ! empty( $ret['ok'] );
			$summary = (string) ( $ret['summary'] ?? '' );
			$error   = (string) ( $ret['error']   ?? '' );
			$data    = (array)  ( $ret['result']  ?? [] );

			$op = [
				'tool'      => $name,
				'ok'        => $ok,
				'args'      => $args,
				'summary'   => $summary,
				'error'     => $error,
				'result'    => $data,
				'ms'        => $call_ms,
			];
			$result['ops'][] = $op;

			if ( $ok ) {
				if ( $name === 'memory_remember' ) {
					$result['persisted']++;
				} elseif ( $name === 'memory_forget' ) {
					$result['forgotten']++;
				} elseif ( $name === 'memory_recall' ) {
					$result['recalled']++;
				}
			} else {
				$result['errors'][] = $name . ':' . ( $error ?: 'unknown' );
			}

			$this->emit( $on_event, $ok ? 'memory_tool_result' : 'memory_tool_error', [
				'trace_id' => $tool_ctx['trace_id'],
				'tool'     => $name,
				'ok'       => $ok,
				'summary'  => $summary,
				'error'    => $error,
				'result'   => $data,
				'ms'       => $call_ms,
			] );

			// Rewrite block in final text.
			$replacements[] = [
				'raw'  => $raw,
				'with' => $this->render_replacement( $name, $ok, $data, $summary, $error ),
			];
		}

		// Apply replacements (each $raw should be unique — preg_match returned $m[0]).
		$rewritten = $final_text;
		foreach ( $replacements as $rep ) {
			$pos = strpos( $rewritten, $rep['raw'] );
			if ( $pos !== false ) {
				$rewritten = substr_replace( $rewritten, $rep['with'], $pos, strlen( $rep['raw'] ) );
			}
		}
		// Trim double blank lines created by stripped blocks.
		$rewritten = preg_replace( "/\n{3,}/", "\n\n", $rewritten );

		$result['rewritten_text'] = trim( (string) $rewritten );
		$result['latency_ms']     = (int) ( ( microtime( true ) - $t0 ) * 1000 );
		return $result;
	}

	/* =================================================================
	 *  Helpers
	 * ================================================================ */

	/**
	 * Find all `<tool name="...">{...}</tool>` blocks (registry parser handles
	 * only first; we need ALL for multi-call turns).
	 *
	 * @return array<int,array{name:string,args:array,raw:string}>
	 */
	private function find_all_blocks( string $text ): array {
		$out = [];
		if ( ! preg_match_all( '#<tool\s+name=["\']([a-z0-9_]+)["\']\s*>(.*?)</tool>#is', $text, $matches, PREG_SET_ORDER ) ) {
			return $out;
		}
		foreach ( $matches as $m ) {
			$name = strtolower( trim( (string) $m[1] ) );
			$raw_args = trim( (string) $m[2] );
			$args = [];
			if ( $raw_args !== '' ) {
				$decoded = json_decode( $raw_args, true );
				if ( is_array( $decoded ) ) $args = $decoded;
			}
			$out[] = [
				'name' => $name,
				'args' => $args,
				'raw'  => (string) $m[0],
			];
		}
		return $out;
	}

	private function render_replacement( string $name, bool $ok, array $data, string $summary, string $error ): string {
		if ( ! $ok ) {
			return '';
		}
		switch ( $name ) {
			case 'memory_remember':
				$token = (string) ( $data['token'] ?? '' );
				return $token !== '' ? ' ' . $token : '';
			case 'memory_forget':
				$text = (string) ( $data['text'] ?? '' );
				return $text !== '' ? sprintf( '_(đã quên: %s)_', mb_substr( $text, 0, 80 ) ) : '';
			case 'memory_recall':
				$count = (int) ( $data['count'] ?? 0 );
				return $count > 0 ? sprintf( '_(🔍 vừa nhớ thêm %d memory)_', $count ) : '';
			default:
				return '';
		}
	}

	private function emit( $cb, string $key, array $payload ): void {
		if ( is_callable( $cb ) ) {
			try {
				call_user_func( $cb, $key, $payload );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[memory_tool_dispatcher][emit] ' . $e->getMessage() );
				}
			}
		}
	}

	/**
	 * Render system-prompt section riêng cho 3 memory tool. Final Composer
	 * inject vào system prompt KHI flag `bizcity_twinbrain_memory_tools_enabled`
	 * ON (default false — opt-in để tránh LLM call quá tay).
	 */
	public function render_prompt_section(): string {
		if ( ! class_exists( 'BizCity_Twin_Tool_Registry' ) ) return '';
		$names = [ 'memory_remember', 'memory_forget', 'memory_recall' ];
		return BizCity_Twin_Tool_Registry::instance()->render_prompt_section( $names );
	}
}
