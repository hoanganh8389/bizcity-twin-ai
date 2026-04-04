<?php
/**
 * Changelog — Phase 1.6: Session Memory Spec Architecture
 *
 * Validates ALL fixes from §20 Đánh Giá Toàn Diện + Sprint hook wiring.
 *
 * @package BizCity_Twin_AI
 * @since   4.2.0
 */

defined( 'ABSPATH' ) or die();

class BizCity_Changelog_Phase16 extends BizCity_Changelog_Base {

	public function get_phase_id(): string {
		return '1.6';
	}

	public function get_phase_title(): string {
		return 'Session Memory Spec Architecture';
	}

	public function get_description(): string {
		return '§20 Đánh Giá Toàn Diện — Session Spec, Context Layers Capture, Mode Transitions, Working Panel Observability';
	}

	public function get_dates(): array {
		return [ 'started' => '2026-03-28', 'updated' => '2026-04-04' ];
	}

	public function get_changelog(): array {
		return [
			[
				'group'   => 'P0-BLOCK — Phá mode / DB waste',
				'icon'    => '🔴',
				'entries' => [
					[ 'id' => 'L1a', 'title' => 'refresh_on_message: isset guard cho mode' ],
					[ 'id' => 'L1b', 'title' => 'refresh_on_message: không default fallback chat' ],
					[ 'id' => 'C1a', 'title' => 'on_prompt_built: set _persisted flag' ],
					[ 'id' => 'C1b', 'title' => 'persist_on_message: check _persisted flag' ],
				],
			],
			[
				'group'   => 'P1-HIGH — Logic sai / Debug blind',
				'icon'    => '🟡',
				'entries' => [
					[ 'id' => 'C2a', 'title' => 'Không có inline on_prompt_built sau do_action' ],
					[ 'id' => 'C2b', 'title' => 'Fix comment §20 C2 documented' ],
					[ 'id' => 'L2',  'title' => 'ensure_started: pre_filter_len === 0 guard' ],
					[ 'id' => 'L6a', 'title' => 'capture_final_prompt: safety persist_to_session trước LLM' ],
					[ 'id' => 'L6b', 'title' => 'Fix comment §20 L6 documented' ],
				],
			],
			[
				'group'   => 'P2-MED — Cosmetic / Perf / Dead code',
				'icon'    => '🟢',
				'entries' => [
					[ 'id' => 'C3',  'title' => 'inject_if_active: xóa should_inject(session) call' ],
					[ 'id' => 'L3a', 'title' => 'on_task_completed: nhận $state parameter' ],
					[ 'id' => 'L3b', 'title' => 'on_task_completed: phân biệt thất bại vs hoàn tất' ],
					[ 'id' => 'L5',  'title' => 'ajax_poll: xóa dead get_latest() code' ],
					[ 'id' => 'D4a', 'title' => 'Poll: setTimeout thay setInterval' ],
					[ 'id' => 'D4b', 'title' => 'Poll: POLL_MAX backoff cap' ],
					[ 'id' => 'D4c', 'title' => 'Poll: exponential backoff logic' ],
				],
			],
			[
				'group'   => 'Hook Wiring & Feature Flag',
				'icon'    => '🔗',
				'entries' => [
					[ 'id' => 'B1',    'title' => 'bizcity_goal_detected has listener' ],
					[ 'id' => 'B3',    'title' => 'bizcity_pipeline_completed has listener' ],
					[ 'id' => 'B6',    'title' => 'bizcity_system_prompt_built has listener' ],
					[ 'id' => 'B7a',   'title' => 'bizcity_chat_system_prompt has filters' ],
					[ 'id' => 'B7b',   'title' => 'bizcity_chat_message_processed has persist listener' ],
					[ 'id' => 'SS-i',  'title' => 'SessionSpec inject_if_active @12 hooked' ],
					[ 'id' => 'SS-r',  'title' => 'SessionSpec refresh_on_message @12 hooked' ],
					[ 'id' => 'FLAG',  'title' => 'All entry points check is_enabled()' ],
				],
			],
		];
	}

	/* ════════════════════════════════════════════════════════════════════
	 * VERIFICATIONS
	 * ════════════════════════════════════════════════════════════════════ */

	protected function run_verifications(): void {
		$this->verify_L1();
		$this->verify_C1();
		$this->verify_C2();
		$this->verify_L2();
		$this->verify_L6();
		$this->verify_C3();
		$this->verify_L3();
		$this->verify_L5();
		$this->verify_D4();
		$this->verify_hooks();
		$this->verify_feature_flags();
	}

	/* ── P0-BLOCK: L1 — mode reset on refresh ── */
	private function verify_L1(): void {
		if ( ! class_exists( 'BizCity_Session_Memory_Spec' ) ) {
			$this->skip( 'L1a', 'Session Memory Spec class not loaded' );
			$this->skip( 'L1b', 'Session Memory Spec class not loaded' );
			return;
		}

		$source = $this->get_method_source( 'BizCity_Session_Memory_Spec', 'refresh_on_message' );

		$has_isset = strpos( $source, "isset( \$data['mode'] )" ) !== false
		          || strpos( $source, "isset(\$data['mode'])" ) !== false;
		$has_fallback = preg_match( '/\$mode\s*=\s*isset.*\?\s*.*:\s*[\'"]chat[\'"]/', $source );

		$this->assert( 'L1a', 'isset guard cho mode', $has_isset,
			$has_isset ? 'isset($data[\'mode\']) guard found' : 'Guard missing' );
		$this->assert( 'L1b', 'Không default chat fallback', ! $has_fallback,
			$has_fallback ? 'Còn fallback chat — mode reset mỗi refresh' : 'Không fallback — mode preserved' );
	}

	/* ── P0-BLOCK: C1 — Double persist ── */
	private function verify_C1(): void {
		if ( ! class_exists( 'BizCity_Context_Layers_Capture' ) ) {
			$this->skip( 'C1a', 'Context Layers Capture not loaded' );
			$this->skip( 'C1b', 'Context Layers Capture not loaded' );
			return;
		}

		$opb = $this->get_method_source( 'BizCity_Context_Layers_Capture', 'on_prompt_built' );
		$pom = $this->get_method_source( 'BizCity_Context_Layers_Capture', 'persist_on_message' );

		$this->assert( 'C1a', 'on_prompt_built sets _persisted flag', strpos( $opb, '_persisted' ) !== false,
			strpos( $opb, '_persisted' ) !== false ? '_persisted flag set before stop()' : 'Flag missing' );
		$this->assert( 'C1b', 'persist_on_message checks _persisted', strpos( $pom, '_persisted' ) !== false,
			strpos( $pom, '_persisted' ) !== false ? 'Early return khi _persisted=true' : 'Missing check' );
	}

	/* ── P1-HIGH: C2 — Inline on_prompt_built ── */
	private function verify_C2(): void {
		if ( ! class_exists( 'BizCity_Twin_Context_Resolver' ) ) {
			$this->skip( 'C2a', 'Twin Context Resolver not loaded' );
			$this->skip( 'C2b', 'Twin Context Resolver not loaded' );
			return;
		}

		$ref  = new ReflectionClass( 'BizCity_Twin_Context_Resolver' );
		$file = $ref->getFileName();

		if ( ! $file || ! is_readable( $file ) ) {
			$this->skip( 'C2a', 'File not readable: ' . ( $file ?: 'unknown' ) );
			$this->skip( 'C2b', 'File not readable' );
			return;
		}

		$source = file_get_contents( $file );

		$has_inline = preg_match(
			'/do_action\s*\(\s*[\'"]bizcity_system_prompt_built[\'"].*?\n.*?BizCity_Context_Layers_Capture::on_prompt_built/s',
			$source
		);
		$has_comment = strpos( $source, 'C2 fix' ) !== false;

		$this->assert( 'C2a', 'Không inline on_prompt_built sau do_action', ! $has_inline,
			$has_inline ? 'Còn inline call → duplicate bootstrap hook' : 'Clean — chỉ bootstrap hook' );
		$this->assert( 'C2b', 'Fix comment documented', $has_comment,
			$has_comment ? '§20 C2 fix comment present' : 'Missing comment' );
	}

	/* ── P1-HIGH: L2 — pre_filter_len guard ── */
	private function verify_L2(): void {
		if ( ! class_exists( 'BizCity_Context_Layers_Capture' ) ) {
			$this->skip( 'L2', 'Context Layers Capture not loaded' );
			return;
		}

		$source = $this->get_method_source( 'BizCity_Context_Layers_Capture', 'ensure_started' );
		$has_guard = strpos( $source, 'pre_filter_len === 0' ) !== false;

		$this->assert( 'L2', 'pre_filter_len === 0 guard', $has_guard,
			$has_guard ? 'Chỉ set pre_filter_len khi === 0' : 'Missing guard — bị overwrite' );
	}

	/* ── P1-HIGH: L6 — Safety persist before LLM ── */
	private function verify_L6(): void {
		if ( ! class_exists( 'BizCity_Context_Layers_Capture' ) ) {
			$this->skip( 'L6a', 'Context Layers Capture not loaded' );
			$this->skip( 'L6b', 'Context Layers Capture not loaded' );
			return;
		}

		$source = $this->get_method_source( 'BizCity_Context_Layers_Capture', 'capture_final_prompt' );

		$this->assert( 'L6a', 'Safety persist_to_session trước LLM', strpos( $source, 'persist_to_session' ) !== false,
			strpos( $source, 'persist_to_session' ) !== false ? 'Snapshot survives LLM failure' : 'Mất snapshot nếu LLM fail' );
		$this->assert( 'L6b', 'Fix comment L6 documented', strpos( $source, 'L6' ) !== false,
			strpos( $source, 'L6' ) !== false ? '§20 L6 comment present' : 'Missing comment' );
	}

	/* ── P2-MED: C3 — Focus Gate session check ── */
	private function verify_C3(): void {
		if ( ! class_exists( 'BizCity_Session_Memory_Spec' ) ) {
			$this->skip( 'C3', 'Session Memory Spec not loaded' );
			return;
		}

		$source = $this->get_method_source( 'BizCity_Session_Memory_Spec', 'inject_if_active' );
		$has_call = $this->source_has_code( $source, 'should_inject' );

		$this->assert( 'C3', 'Xóa should_inject(session) call', ! $has_call,
			$has_call ? 'Còn gọi should_inject — meaningless check' : 'Clean — comment-only reference OK' );
	}

	/* ── P2-MED: L3 — task failed message ── */
	private function verify_L3(): void {
		if ( ! class_exists( 'BizCity_Session_Memory_Spec' ) ) {
			$this->skip( 'L3a', 'Session Memory Spec not loaded' );
			$this->skip( 'L3b', 'Session Memory Spec not loaded' );
			return;
		}

		$ref    = new ReflectionMethod( 'BizCity_Session_Memory_Spec', 'on_task_completed' );
		$params = array_map( fn( $p ) => $p->getName(), $ref->getParameters() );
		$source = $this->read_method_source( $ref->getFileName(), $ref->getStartLine(), $ref->getEndLine() );

		$this->assert( 'L3a', 'on_task_completed nhận $state param', in_array( 'state', $params, true ),
			'params=[' . implode( ', ', $params ) . ']' );
		$this->assert( 'L3b', 'Phân biệt thất bại vs hoàn tất',
			strpos( $source, 'failed' ) !== false && strpos( $source, 'thất bại' ) !== false,
			strpos( $source, 'thất bại' ) !== false ? '"thất bại" cho failed pipelines' : 'Luôn nói "hoàn tất"' );
	}

	/* ── P2-MED: L5 — Dead get_latest() ── */
	private function verify_L5(): void {
		if ( ! class_exists( 'BizCity_Working_Panel_Context' ) ) {
			$this->skip( 'L5', 'Working Panel Context not loaded (OK nếu không admin)' );
			return;
		}

		$source = $this->get_method_source( 'BizCity_Working_Panel_Context', 'ajax_poll' );

		$this->assert( 'L5', 'Xóa dead get_latest() code', strpos( $source, 'get_latest' ) === false,
			strpos( $source, 'get_latest' ) === false ? 'Clean — reads only from DB' : 'Còn get_latest() — dead code' );
	}

	/* ── P2-MED: D4 — Poll backoff ── */
	private function verify_D4(): void {
		if ( ! class_exists( 'BizCity_Working_Panel_Context' ) ) {
			$this->skip( 'D4a', 'Working Panel Context not loaded' );
			$this->skip( 'D4b', 'Working Panel Context not loaded' );
			$this->skip( 'D4c', 'Working Panel Context not loaded' );
			return;
		}

		$ref  = new ReflectionClass( 'BizCity_Working_Panel_Context' );
		$file = $ref->getFileName();

		if ( ! $file || ! is_readable( $file ) ) {
			$this->skip( 'D4a', 'File not readable' );
			$this->skip( 'D4b', 'File not readable' );
			$this->skip( 'D4c', 'File not readable' );
			return;
		}

		$source = file_get_contents( $file );

		$this->assert( 'D4a', 'setTimeout thay setInterval',
			strpos( $source, 'setTimeout' ) !== false && strpos( $source, 'setInterval' ) === false,
			strpos( $source, 'setInterval' ) !== false ? 'Còn setInterval — no backoff' : 'setTimeout-based scheduling' );

		$this->assert( 'D4b', 'POLL_MAX backoff cap', strpos( $source, 'POLL_MAX' ) !== false,
			strpos( $source, 'POLL_MAX' ) !== false ? 'POLL_MAX defined' : 'Interval tăng mãi' );

		$has_backoff = strpos( $source, 'pollInterval * 2' ) !== false
		           || strpos( $source, 'pollInterval *2' ) !== false;
		$this->assert( 'D4c', 'Exponential backoff logic', $has_backoff,
			$has_backoff ? 'pollInterval doubles khi data unchanged' : 'Constant 5s polling' );
	}

	/* ── Hook Wiring ── */
	private function verify_hooks(): void {
		$this->assert( 'B1', 'bizcity_goal_detected has listener', has_action( 'bizcity_goal_detected' ) !== false );
		$this->assert( 'B3', 'bizcity_pipeline_completed has listener', has_action( 'bizcity_pipeline_completed' ) !== false );
		$this->assert( 'B6', 'bizcity_system_prompt_built has listener', has_action( 'bizcity_system_prompt_built' ) !== false );
		$this->assert( 'B7a', 'bizcity_chat_system_prompt has filters', has_filter( 'bizcity_chat_system_prompt' ) !== false );
		$this->assert( 'B7b', 'bizcity_chat_message_processed has listener', has_action( 'bizcity_chat_message_processed' ) !== false );

		if ( class_exists( 'BizCity_Session_Memory_Spec' ) ) {
			$this->assert( 'SS-i', 'SessionSpec inject_if_active @12 hooked',
				has_filter( 'bizcity_chat_system_prompt', [ 'BizCity_Session_Memory_Spec', 'inject_if_active' ] ) !== false );
			$this->assert( 'SS-r', 'SessionSpec refresh_on_message @12 hooked',
				has_action( 'bizcity_chat_message_processed', [ 'BizCity_Session_Memory_Spec', 'refresh_on_message' ] ) !== false );
		} else {
			$this->skip( 'SS-i', 'SessionSpec not loaded' );
			$this->skip( 'SS-r', 'SessionSpec not loaded' );
		}
	}

	/* ── Feature Flag Guards ── */
	private function verify_feature_flags(): void {
		if ( ! class_exists( 'BizCity_Session_Memory_Spec' ) ) {
			$this->skip( 'FLAG', 'Session Memory Spec not loaded' );
			return;
		}

		$check = [ 'inject_if_active', 'refresh_on_message', 'on_goal_detected', 'on_task_created', 'on_task_completed' ];
		$all_ok = true;
		$details = [];

		foreach ( $check as $name ) {
			if ( ! method_exists( 'BizCity_Session_Memory_Spec', $name ) ) {
				$details[] = "{$name}=MISSING";
				$all_ok = false;
				continue;
			}
			$src = $this->get_method_source( 'BizCity_Session_Memory_Spec', $name );
			$ok  = strpos( $src, 'is_enabled' ) !== false;
			$details[] = "{$name}=" . ( $ok ? '✅' : '❌' );
			if ( ! $ok ) {
				$all_ok = false;
			}
		}

		$this->assert( 'FLAG', 'All entry points check is_enabled()', $all_ok, implode( ', ', $details ) );
	}
}
