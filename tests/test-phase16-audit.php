<?php
/**
 * Phase 1.6 — Audit Fix Verification Test
 *
 * Validates ALL fixes from §20 Đánh Giá Toàn Diện:
 *   P0-BLOCK: L1, C1
 *   P1-HIGH:  C2, L2, L6
 *   P2-MED:   C3, L3, L5, D4
 *
 * Run via: wp-admin/admin.php?page=bizcity-test-phase16
 * Or WP-CLI: wp eval-file wp-content/plugins/bizcity-twin-ai/tests/test-phase16-audit.php
 *
 * @package BizCity_Twin_AI
 * @since   4.1.0 Phase 1.6 Sprint 2
 */

defined( 'ABSPATH' ) or die( 'Must be loaded via WordPress.' );

class BizCity_Phase16_Audit_Test {

	private $results = [];
	private $pass    = 0;
	private $fail    = 0;

	public function run(): array {
		$this->results = [];
		$this->pass    = 0;
		$this->fail    = 0;

		// P0-BLOCK
		$this->test_L1_mode_preserved_on_refresh();
		$this->test_C1_no_double_persist();

		// P1-HIGH
		$this->test_C2_no_inline_on_prompt_built();
		$this->test_L2_pre_filter_len_guard();
		$this->test_L6_safety_persist_before_llm();

		// P2-MED
		$this->test_C3_no_focus_gate_session_check();
		$this->test_L3_task_failed_message();
		$this->test_L5_no_live_snapshot_dead_code();
		$this->test_D4_poll_backoff();

		// Hook wiring verification
		$this->test_hook_wiring_complete();
		$this->test_feature_flag_guards();

		return [
			'pass'    => $this->pass,
			'fail'    => $this->fail,
			'total'   => $this->pass + $this->fail,
			'results' => $this->results,
		];
	}

	private function assert( string $name, bool $condition, string $detail = '' ): void {
		if ( $condition ) {
			$this->pass++;
			$this->results[] = [ 'name' => $name, 'status' => '✅ PASS', 'detail' => $detail ];
		} else {
			$this->fail++;
			$this->results[] = [ 'name' => $name, 'status' => '❌ FAIL', 'detail' => $detail ];
		}
	}

	/* ══════════════════════════════════════════════════════════════════════
	 * P0-BLOCK: L1 — mode bị reset mỗi refresh_on_message
	 * ══════════════════════════════════════════════════════════════════════ */
	private function test_L1_mode_preserved_on_refresh(): void {
		if ( ! class_exists( 'BizCity_Session_Memory_Spec' ) ) {
			$this->assert( '[L1] Session Memory Spec class exists', false, 'Class not loaded' );
			return;
		}

		// Verify via Reflection: refresh_on_message checks isset($data['mode'])
		// NOT a default fallback to 'chat'
		$ref = new ReflectionClass( 'BizCity_Session_Memory_Spec' );
		$method = $ref->getMethod( 'refresh_on_message' );

		$file = $method->getFileName();
		$start = $method->getStartLine();
		$end = $method->getEndLine();

		$source = $this->read_method_source( $file, $start, $end );

		// The fix: must use isset($data['mode']) — NOT: $mode = isset($data['mode']) ? ... : 'chat'
		$has_isset_guard = strpos( $source, "isset( \$data['mode'] )" ) !== false
		                || strpos( $source, "isset(\$data['mode'])" ) !== false;

		// Must NOT have a fallback to 'chat' for mode
		$has_chat_default = preg_match( '/\$mode\s*=\s*isset.*\?\s*.*:\s*[\'"]chat[\'"]/', $source );

		$this->assert(
			'[L1] refresh_on_message uses isset guard for mode',
			$has_isset_guard,
			$has_isset_guard ? 'isset($data[\'mode\']) guard found' : 'Guard missing — mode will reset on each message'
		);

		$this->assert(
			'[L1] refresh_on_message has no default chat fallback',
			! $has_chat_default,
			$has_chat_default ? 'Still has default \'chat\' fallback — mode transitions broken' : 'No default fallback — mode preserved'
		);
	}

	/* ══════════════════════════════════════════════════════════════════════
	 * P0-BLOCK: C1 — Double persist trên twin_resolver path
	 * ══════════════════════════════════════════════════════════════════════ */
	private function test_C1_no_double_persist(): void {
		if ( ! class_exists( 'BizCity_Context_Layers_Capture' ) ) {
			$this->assert( '[C1] Context Layers Capture class exists', false, 'Class not loaded' );
			return;
		}

		$ref = new ReflectionClass( 'BizCity_Context_Layers_Capture' );

		// Part A: on_prompt_built sets _persisted flag
		$opb = $ref->getMethod( 'on_prompt_built' );
		$opb_source = $this->read_method_source( $opb->getFileName(), $opb->getStartLine(), $opb->getEndLine() );

		$sets_persisted = strpos( $opb_source, '_persisted' ) !== false;
		$this->assert(
			'[C1a] on_prompt_built sets _persisted flag',
			$sets_persisted,
			$sets_persisted ? '_persisted flag set before stop()' : 'Flag missing — persist_on_message will double-persist'
		);

		// Part B: persist_on_message checks _persisted flag
		$pom = $ref->getMethod( 'persist_on_message' );
		$pom_source = $this->read_method_source( $pom->getFileName(), $pom->getStartLine(), $pom->getEndLine() );

		$checks_persisted = strpos( $pom_source, '_persisted' ) !== false;
		$this->assert(
			'[C1b] persist_on_message checks _persisted flag',
			$checks_persisted,
			$checks_persisted ? 'Early return when _persisted=true' : 'Missing check — will double-persist'
		);
	}

	/* ══════════════════════════════════════════════════════════════════════
	 * P1-HIGH: C2 — on_prompt_built gọi 2 lần (inline + hook)
	 * ══════════════════════════════════════════════════════════════════════ */
	private function test_C2_no_inline_on_prompt_built(): void {
		if ( ! class_exists( 'BizCity_Twin_Context_Resolver' ) ) {
			$this->assert( '[C2] Twin Context Resolver class exists', false, 'Class not loaded' );
			return;
		}

		$ref = new ReflectionClass( 'BizCity_Twin_Context_Resolver' );
		$file = $ref->getFileName();

		if ( ! $file || ! is_readable( $file ) ) {
			$this->assert( '[C2] Can read resolver file', false, 'File not readable: ' . ( $file ?: 'unknown' ) );
			return;
		}

		$source = file_get_contents( $file );

		// After do_action('bizcity_system_prompt_built'), there should NOT be a direct call
		$has_inline_call = preg_match(
			'/do_action\s*\(\s*[\'"]bizcity_system_prompt_built[\'"].*?\n.*?BizCity_Context_Layers_Capture::on_prompt_built/s',
			$source
		);

		// Also verify the §20 C2 comment
		$has_fix_comment = strpos( $source, 'C2 fix' ) !== false;

		$this->assert(
			'[C2] No inline on_prompt_built after do_action',
			! $has_inline_call,
			$has_inline_call ? 'Still has inline call — duplicates bootstrap hook' : 'Clean — only bootstrap hook fires'
		);

		$this->assert(
			'[C2] Fix comment documented',
			$has_fix_comment,
			$has_fix_comment ? '§20 C2 fix comment present' : 'Missing fix comment'
		);
	}

	/* ══════════════════════════════════════════════════════════════════════
	 * P1-HIGH: L2 — pre_filter_len ghi đè khi twin_resolver start
	 * ══════════════════════════════════════════════════════════════════════ */
	private function test_L2_pre_filter_len_guard(): void {
		if ( ! class_exists( 'BizCity_Context_Layers_Capture' ) ) {
			$this->assert( '[L2] Context Layers Capture class exists', false );
			return;
		}

		$ref = new ReflectionClass( 'BizCity_Context_Layers_Capture' );
		$method = $ref->getMethod( 'ensure_started' );
		$source = $this->read_method_source( $method->getFileName(), $method->getStartLine(), $method->getEndLine() );

		// Fix: pre_filter_len only set when current value === 0
		$has_guard = strpos( $source, 'pre_filter_len === 0' ) !== false;

		$this->assert(
			'[L2] ensure_started guards pre_filter_len overwrite',
			$has_guard,
			$has_guard ? 'Only sets pre_filter_len when === 0' : 'Missing guard — twin_resolver pre_filter_len gets overwritten'
		);
	}

	/* ══════════════════════════════════════════════════════════════════════
	 * P1-HIGH: L6 — Failed LLM call → snapshot mất
	 * ══════════════════════════════════════════════════════════════════════ */
	private function test_L6_safety_persist_before_llm(): void {
		if ( ! class_exists( 'BizCity_Context_Layers_Capture' ) ) {
			$this->assert( '[L6] Context Layers Capture class exists', false );
			return;
		}

		$ref = new ReflectionClass( 'BizCity_Context_Layers_Capture' );
		$method = $ref->getMethod( 'capture_final_prompt' );
		$source = $this->read_method_source( $method->getFileName(), $method->getStartLine(), $method->getEndLine() );

		// Fix: must call persist_to_session INSIDE capture_final_prompt
		$has_safety_persist = strpos( $source, 'persist_to_session' ) !== false;

		$this->assert(
			'[L6] capture_final_prompt safety-persists before LLM',
			$has_safety_persist,
			$has_safety_persist
				? 'persist_to_session called in capture_final_prompt — snapshot survives LLM failure'
				: 'No safety persist — snapshot lost if LLM fails'
		);

		// Also verify the L6 comment  
		$has_comment = strpos( $source, 'L6' ) !== false;
		$this->assert(
			'[L6] Fix comment documented',
			$has_comment,
			$has_comment ? '§20 L6 fix comment present' : 'Missing fix comment'
		);
	}

	/* ══════════════════════════════════════════════════════════════════════
	 * P2-MED: C3 — Focus Gate 'session' layer luôn true
	 * ══════════════════════════════════════════════════════════════════════ */
	private function test_C3_no_focus_gate_session_check(): void {
		if ( ! class_exists( 'BizCity_Session_Memory_Spec' ) ) {
			$this->assert( '[C3] Session Memory Spec class exists', false );
			return;
		}

		$ref = new ReflectionClass( 'BizCity_Session_Memory_Spec' );
		$method = $ref->getMethod( 'inject_if_active' );
		$source = $this->read_method_source( $method->getFileName(), $method->getStartLine(), $method->getEndLine() );

		// Fix: should NOT have should_inject('session') CALL (ignore comment lines)
		$has_session_gate = false;
		foreach ( explode( "\n", $source ) as $line ) {
			$trimmed = ltrim( $line );
			// Skip comment lines
			if ( str_starts_with( $trimmed, '//' ) || str_starts_with( $trimmed, '*' ) || str_starts_with( $trimmed, '/*' ) ) {
				continue;
			}
			if ( strpos( $trimmed, 'should_inject' ) !== false ) {
				$has_session_gate = true;
				break;
			}
		}

		$this->assert(
			'[C3] inject_if_active has no should_inject(session) call',
			! $has_session_gate,
			$has_session_gate
				? 'Still calls should_inject — meaningless check (session layer not in profile)'
				: 'Clean — no Focus Gate check for session layer (comment-only reference OK)'
		);
	}

	/* ══════════════════════════════════════════════════════════════════════
	 * P2-MED: L3 — on_task_completed message sai khi FAILED
	 * ══════════════════════════════════════════════════════════════════════ */
	private function test_L3_task_failed_message(): void {
		if ( ! class_exists( 'BizCity_Session_Memory_Spec' ) ) {
			$this->assert( '[L3] Session Memory Spec class exists', false );
			return;
		}

		$ref = new ReflectionClass( 'BizCity_Session_Memory_Spec' );
		$method = $ref->getMethod( 'on_task_completed' );

		// Part A: Check method signature has $state parameter
		$params = $method->getParameters();
		$param_names = array_map( fn( $p ) => $p->getName(), $params );
		$has_state_param = in_array( 'state', $param_names, true );

		$this->assert(
			'[L3a] on_task_completed accepts $state param',
			$has_state_param,
			'params=[' . implode( ', ', $param_names ) . ']'
		);

		// Part B: Check source distinguishes failed vs completed
		$source = $this->read_method_source( $method->getFileName(), $method->getStartLine(), $method->getEndLine() );
		$has_failed_check = strpos( $source, 'failed' ) !== false;
		$has_focus_distinction = strpos( $source, 'thất bại' ) !== false;

		$this->assert(
			'[L3b] on_task_completed distinguishes failed pipelines',
			$has_failed_check && $has_focus_distinction,
			$has_focus_distinction
				? 'Shows "thất bại" for failed pipelines'
				: 'No failed/completed distinction — always says "hoàn tất"'
		);
	}

	/* ══════════════════════════════════════════════════════════════════════
	 * P2-MED: L5 — AJAX live snapshot luôn trống
	 * ══════════════════════════════════════════════════════════════════════ */
	private function test_L5_no_live_snapshot_dead_code(): void {
		if ( ! class_exists( 'BizCity_Working_Panel_Context' ) ) {
			$this->assert( '[L5] Working Panel Context class exists', false, 'Class not loaded (OK if not admin)' );
			return;
		}

		$ref = new ReflectionClass( 'BizCity_Working_Panel_Context' );
		$method = $ref->getMethod( 'ajax_poll' );
		$source = $this->read_method_source( $method->getFileName(), $method->getStartLine(), $method->getEndLine() );

		// Fix: should NOT call get_latest() — dead code removed
		$has_get_latest = strpos( $source, 'get_latest' ) !== false;

		$this->assert(
			'[L5] ajax_poll has no dead get_latest() call',
			! $has_get_latest,
			$has_get_latest
				? 'Still calls get_latest() — dead code (static per-request, always empty in AJAX)'
				: 'Clean — reads only from DB'
		);
	}

	/* ══════════════════════════════════════════════════════════════════════
	 * P2-MED: D4 — Poll 5s không backoff
	 * ══════════════════════════════════════════════════════════════════════ */
	private function test_D4_poll_backoff(): void {
		if ( ! class_exists( 'BizCity_Working_Panel_Context' ) ) {
			$this->assert( '[D4] Working Panel Context class exists', false, 'Class not loaded' );
			return;
		}

		$ref = new ReflectionClass( 'BizCity_Working_Panel_Context' );
		$file = $ref->getFileName();

		if ( ! $file || ! is_readable( $file ) ) {
			$this->assert( '[D4] Can read Working Panel file', false );
			return;
		}

		$source = file_get_contents( $file );

		// Fix: must use setTimeout-based scheduling, NOT setInterval
		$has_set_timeout = strpos( $source, 'setTimeout' ) !== false;
		$has_set_interval = strpos( $source, 'setInterval' ) !== false;

		// Fix: must have POLL_MAX for backoff cap
		$has_poll_max = strpos( $source, 'POLL_MAX' ) !== false;

		// Fix: must have backoff logic (double interval when unchanged)
		$has_backoff = strpos( $source, 'pollInterval * 2' ) !== false
		           || strpos( $source, 'pollInterval *2' ) !== false
		           || strpos( $source, 'pollInterval*2' ) !== false;

		$this->assert(
			'[D4a] Uses setTimeout (not setInterval) for poll scheduling',
			$has_set_timeout && ! $has_set_interval,
			$has_set_interval ? 'Still uses setInterval — no backoff possible' : 'setTimeout-based scheduling'
		);

		$this->assert(
			'[D4b] Has POLL_MAX backoff cap',
			$has_poll_max,
			$has_poll_max ? 'POLL_MAX defined — prevents excessive interval' : 'No cap — interval grows forever'
		);

		$this->assert(
			'[D4c] Has exponential backoff logic',
			$has_backoff,
			$has_backoff ? 'pollInterval doubles when data unchanged' : 'No backoff — constant 5s polling'
		);
	}

	/* ══════════════════════════════════════════════════════════════════════
	 * BONUS: Hook wiring complete (Sprint 2 B1-B8)
	 * ══════════════════════════════════════════════════════════════════════ */
	private function test_hook_wiring_complete(): void {
		// B1: Goal detected hook
		$has_goal = has_action( 'bizcity_goal_detected' );
		$this->assert( '[B1] bizcity_goal_detected has listener', $has_goal !== false );

		// B3: Pipeline completed hook
		$has_completed = has_action( 'bizcity_pipeline_completed' );
		$this->assert( '[B3] bizcity_pipeline_completed has listener', $has_completed !== false );

		// B6: System prompt built listener
		$has_prompt_built = has_action( 'bizcity_system_prompt_built' );
		$this->assert( '[B6] bizcity_system_prompt_built has listener', $has_prompt_built !== false );

		// B7: Universal capture filters
		$has_ensure = has_filter( 'bizcity_chat_system_prompt' );
		$this->assert( '[B7] bizcity_chat_system_prompt has filters (@0/@99)', $has_ensure !== false );

		// B7: Persist on message
		$has_persist = has_action( 'bizcity_chat_message_processed' );
		$this->assert( '[B7] bizcity_chat_message_processed has persist listener', $has_persist !== false );

		// Session Spec inject + refresh
		if ( class_exists( 'BizCity_Session_Memory_Spec' ) ) {
			$inject_hooked = has_filter( 'bizcity_chat_system_prompt', [ 'BizCity_Session_Memory_Spec', 'inject_if_active' ] );
			$refresh_hooked = has_action( 'bizcity_chat_message_processed', [ 'BizCity_Session_Memory_Spec', 'refresh_on_message' ] );
			$this->assert( '[Sprint1] SessionSpec inject_if_active @12 hooked', $inject_hooked !== false );
			$this->assert( '[Sprint1] SessionSpec refresh_on_message @12 hooked', $refresh_hooked !== false );
		}
	}

	/* ══════════════════════════════════════════════════════════════════════
	 * BONUS: Feature flag guards consistent
	 * ══════════════════════════════════════════════════════════════════════ */
	private function test_feature_flag_guards(): void {
		if ( ! class_exists( 'BizCity_Session_Memory_Spec' ) ) {
			$this->assert( '[FLAG] Session Memory Spec class exists', false );
			return;
		}

		$ref = new ReflectionClass( 'BizCity_Session_Memory_Spec' );

		// All entry points must check is_enabled()
		$methods_to_check = [ 'inject_if_active', 'refresh_on_message', 'on_goal_detected', 'on_task_created', 'on_task_completed' ];

		$all_guarded = true;
		$details = [];
		foreach ( $methods_to_check as $name ) {
			if ( ! $ref->hasMethod( $name ) ) {
				$details[] = "{$name}=MISSING";
				$all_guarded = false;
				continue;
			}

			$m = $ref->getMethod( $name );
			$source = $this->read_method_source( $m->getFileName(), $m->getStartLine(), $m->getEndLine() );

			$has_guard = strpos( $source, 'is_enabled' ) !== false;
			$details[] = "{$name}=" . ( $has_guard ? '✅' : '❌' );
			if ( ! $has_guard ) {
				$all_guarded = false;
			}
		}

		$this->assert(
			'[FLAG] All entry points check is_enabled()',
			$all_guarded,
			implode( ', ', $details )
		);
	}

	/* ══════════════════════════════════════════════════════════════════════
	 * HELPER: Read method source code from file
	 * ══════════════════════════════════════════════════════════════════════ */
	private function read_method_source( string $file, int $start, int $end ): string {
		if ( ! $file || ! is_readable( $file ) ) {
			return '';
		}

		$lines = file( $file );
		if ( ! $lines ) {
			return '';
		}

		// Lines are 1-indexed from Reflection
		$slice = array_slice( $lines, $start - 1, $end - $start + 1 );
		return implode( '', $slice );
	}

	/* ══════════════════════════════════════════════════════════════════════
	 * OUTPUT
	 * ══════════════════════════════════════════════════════════════════════ */
	public function render_html(): string {
		$data = $this->run();
		$html  = '<div style="font-family:monospace;max-width:900px;margin:20px auto;padding:20px">';
		$html .= '<h2>🔍 Phase 1.6 — Audit Fix Verification</h2>';
		$html .= '<p style="color:#64748b">§20 Đánh Giá Toàn Diện — Kiểm tra tất cả fix đã apply</p>';
		$html .= '<p><strong>' . $data['pass'] . '</strong>/' . $data['total'] . ' passed';
		if ( $data['fail'] > 0 ) {
			$html .= ' · <span style="color:#ef4444"><strong>' . $data['fail'] . ' FAILED</strong></span>';
		} else {
			$html .= ' · <span style="color:#059669"><strong>ALL PASS</strong></span>';
		}
		$html .= '</p>';

		// Group results by priority
		$groups = [
			'P0-BLOCK' => [],
			'P1-HIGH'  => [],
			'P2-MED'   => [],
			'WIRING'   => [],
		];

		foreach ( $data['results'] as $r ) {
			$name = $r['name'];
			if ( str_starts_with( $name, '[L1]' ) || str_starts_with( $name, '[C1' ) ) {
				$groups['P0-BLOCK'][] = $r;
			} elseif ( str_starts_with( $name, '[C2]' ) || str_starts_with( $name, '[L2]' ) || str_starts_with( $name, '[L6]' ) ) {
				$groups['P1-HIGH'][] = $r;
			} elseif ( str_starts_with( $name, '[C3]' ) || str_starts_with( $name, '[L3' ) || str_starts_with( $name, '[L5]' ) || str_starts_with( $name, '[D4' ) ) {
				$groups['P2-MED'][] = $r;
			} else {
				$groups['WIRING'][] = $r;
			}
		}

		$prio_labels = [
			'P0-BLOCK' => '🔴 P0-BLOCK — Phá mode / DB waste',
			'P1-HIGH'  => '🟡 P1-HIGH — Logic sai / Debug blind',
			'P2-MED'   => '🟢 P2-MED — Cosmetic / Perf / Dead code',
			'WIRING'   => '🔗 Hook Wiring & Feature Flag',
		];

		foreach ( $groups as $key => $items ) {
			if ( empty( $items ) ) {
				continue;
			}
			$html .= '<h3 style="margin-top:16px;border-bottom:1px solid #e2e8f0;padding-bottom:4px">' . $prio_labels[ $key ] . '</h3>';
			foreach ( $items as $r ) {
				$is_pass = strpos( $r['status'], 'PASS' ) !== false;
				$color = $is_pass ? '#059669' : '#ef4444';
				$bg    = $is_pass ? '#f0fdf4' : '#fef2f2';
				$html .= '<div style="margin:4px 0;padding:6px 10px;border-left:3px solid ' . $color . ';background:' . $bg . '">';
				$html .= '<strong>' . esc_html( $r['status'] ) . '</strong> ' . esc_html( $r['name'] );
				if ( $r['detail'] ) {
					$html .= '<br><span style="color:#64748b;font-size:12px">' . esc_html( $r['detail'] ) . '</span>';
				}
				$html .= '</div>';
			}
		}

		$html .= '</div>';
		return $html;
	}
}

/* ── Register admin page ── */
add_action( 'admin_menu', function () {
	add_submenu_page(
		null,
		'Phase 1.6 Audit Test',
		'Phase 1.6 Audit Test',
		'manage_options',
		'bizcity-test-phase16',
		function () {
			$test = new BizCity_Phase16_Audit_Test();
			echo $test->render_html();
		}
	);
} );

/* ── WP-CLI support ── */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$test = new BizCity_Phase16_Audit_Test();
	$data = $test->run();
	foreach ( $data['results'] as $r ) {
		$line = $r['status'] . ' ' . $r['name'];
		if ( $r['detail'] ) {
			$line .= ' — ' . $r['detail'];
		}
		WP_CLI::log( $line );
	}
	WP_CLI::log( '' );
	WP_CLI::log( $data['pass'] . '/' . $data['total'] . ' passed' );
	if ( $data['fail'] > 0 ) {
		WP_CLI::error( $data['fail'] . ' test(s) failed', false );
	} else {
		WP_CLI::success( 'All tests passed!' );
	}
}
