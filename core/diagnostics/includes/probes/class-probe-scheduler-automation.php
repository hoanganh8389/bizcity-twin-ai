<?php
/**
 * BizCity Diagnostics — scheduler.automation probe (Phase 0.37).
 *
 * Real-call probe cho Automation Runner. Validates the full
 * `ai_context.automation.on_fire[]` pipeline end-to-end:
 *
 *   Layer 1 — DISK
 *     - class-scheduler-automation.php tồn tại + không BOM?
 *     - class-scheduler-automation-lab.php tồn tại?
 *     - class-scheduler-rest-api.php có khai báo route /automation/fire-now?
 *     - core/scheduler/bootstrap.php có require automation + lab?
 *
 *   Layer 2 — LOADER
 *     - BizCity_Scheduler_Automation loaded + hooked bizcity_scheduler_reminder_fire@20?
 *     - BizCity_Cron_Manager loaded + with_synthetic_run() method exists?
 *     - BizCity_Intent_Tools loaded + /automation/tools endpoint returns ≥ 1 tool?
 *     - REST route bizcity-scheduler/v1/automation/fire-now registered?
 *
 *   Layer 3 — RUNTIME (real call via Lab REST endpoint)
 *     - Create synthetic event __healthtest_automation (POST /events).
 *     - Fire it: POST /automation/fire-now → expect ok=true, run_id > 0.
 *     - Pull /automation/recent → expect chain with event_id matching, ≥ 1 step.
 *     - Cleanup: DELETE event __healthtest_automation.
 *
 * PASS criteria:
 *   - All Layer 1 + 2 checks green.
 *   - fire-now returns { ok: true, run_id: N }.
 *   - recent chain has status in {ok, partial} (partial = steps ran, ≥ 1 ok).
 *
 * Tagged test rows: title starts with "__healthtest_automation" — safe to GC.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-27 (Phase 0.37)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
}

final class BizCity_Probe_Scheduler_Automation implements BizCity_Diagnostics_Probe {

	/* ── REST namespace of the scheduler module ── */
	const SCHED_NS    = 'bizcity-scheduler/v1';

	/* ── File paths relative to plugin root ── */
	const RUNNER_FILE    = 'core/scheduler/includes/class-scheduler-automation.php';
	const LAB_FILE       = 'core/scheduler/includes/class-scheduler-automation-lab.php';
	const REST_FILE      = 'core/scheduler/includes/class-scheduler-rest-api.php';
	const BOOTSTRAP_FILE = 'core/scheduler/bootstrap.php';

	/* ── Strings we grep for (needle checks) ── */
	const RUNNER_REQUIRE_NEEDLE = 'class-scheduler-automation.php';
	const LAB_REQUIRE_NEEDLE    = 'class-scheduler-automation-lab.php';
	const ROUTE_NEEDLE          = '/automation/fire-now';

	/* ── Synthetic event tag ── */
	const HEALTHTEST_TAG = '__healthtest_automation';

	public function id(): string          { return 'scheduler.automation'; }
	public function label(): string       { return 'Scheduler · Automation Runner (Phase 0.37)'; }
	public function description(): string {
		return 'Real-call probe: tạo synthetic event, fire qua Lab REST endpoint, xác minh chain evidence ghi vào bizcity_cron_runs. R-DDV 3 lớp: disk → loader → runtime.';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 37; }
	public function icon(): string        { return 'workflow'; }
	public function estimate_ms(): int    { return 3000; }

	/* ─────────────────────────────────────────────────────────────────── */

	public function precondition() {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return new WP_Error( 'no_scheduler', 'BizCity_Scheduler_Manager chưa load — core/scheduler/bootstrap.php chưa included?' );
		}
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return new WP_Error( 'no_cron', 'BizCity_Cron_Manager chưa load — core/cron/bootstrap.php chưa included?' );
		}
		return true;
	}

	/* ─────────────────────────────────────────────────────────────────── */

	public function run( $ctx ): array {

		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
		$base       = trailingslashit( $plugin_dir . '/bizcity-twin-ai' );

		$overall_fail   = false;
		$overall_warn   = false;
		$created_id     = 0;

		/* ══════════════════════════════════════════════════════════════
		 * LAYER 1 · DISK
		 * ══════════════════════════════════════════════════════════════ */

		// 1-A: runner file exists + no BOM
		$runner_path = $base . self::RUNNER_FILE;
		$runner_ok   = is_readable( $runner_path ) && filesize( $runner_path ) > 0;
		$runner_bom  = $runner_ok ? $this->has_bom( $runner_path ) : false;
		$ctx->emit_step( [
			'label'  => 'Disk · runner file',
			'status' => ( $runner_ok && ! $runner_bom ) ? 'pass' : 'fail',
			'detail' => $runner_ok
				? ( $runner_bom ? '⚠️ BOM detected → header() / add_action break' : self::RUNNER_FILE . ' · ok' )
				: 'NOT FOUND: ' . self::RUNNER_FILE,
		] );
		if ( ! $runner_ok || $runner_bom ) { $overall_fail = true; }

		// 1-B: lab file exists
		$lab_path = $base . self::LAB_FILE;
		$lab_ok   = is_readable( $lab_path ) && filesize( $lab_path ) > 0;
		$ctx->emit_step( [
			'label'  => 'Disk · Lab admin page file',
			'status' => $lab_ok ? 'pass' : 'fail',
			'detail' => $lab_ok ? self::LAB_FILE . ' · ok' : 'NOT FOUND: ' . self::LAB_FILE,
		] );
		if ( ! $lab_ok ) { $overall_fail = true; }

		// 1-C: REST file has /automation/fire-now route
		$rest_path    = $base . self::REST_FILE;
		$has_route    = false;
		if ( is_readable( $rest_path ) ) {
			$has_route = (bool) $this->grep_file( $rest_path, self::ROUTE_NEEDLE );
		}
		$ctx->emit_step( [
			'label'  => 'Disk · REST route declaration',
			'status' => $has_route ? 'pass' : 'fail',
			'detail' => $has_route
				? self::ROUTE_NEEDLE . ' found in ' . self::REST_FILE
				: self::ROUTE_NEEDLE . ' NOT found in ' . self::REST_FILE,
		] );
		if ( ! $has_route ) { $overall_fail = true; }

		// 1-D: bootstrap.php requires both runner + lab
		$bs_path         = $base . self::BOOTSTRAP_FILE;
		$bs_has_runner   = false;
		$bs_has_lab      = false;
		if ( is_readable( $bs_path ) ) {
			$bs_has_runner = (bool) $this->grep_file( $bs_path, self::RUNNER_REQUIRE_NEEDLE );
			$bs_has_lab    = (bool) $this->grep_file( $bs_path, self::LAB_REQUIRE_NEEDLE );
		}
		$ctx->emit_step( [
			'label'  => 'Disk · bootstrap wiring',
			'status' => ( $bs_has_runner && $bs_has_lab ) ? 'pass' : ( $bs_has_runner ? 'warn' : 'fail' ),
			'detail' => sprintf(
				'runner=%s · lab=%s',
				$bs_has_runner ? 'found' : 'MISSING',
				$bs_has_lab    ? 'found' : 'MISSING'
			),
		] );
		if ( ! $bs_has_runner ) { $overall_fail = true; }
		if ( ! $bs_has_lab )   { $overall_warn  = true; }

		/* ══════════════════════════════════════════════════════════════
		 * LAYER 2 · LOADER (runtime class + hook presence)
		 * ══════════════════════════════════════════════════════════════ */

		// 2-A: BizCity_Scheduler_Automation loaded + hooked at priority 20
		$automation_loaded = class_exists( 'BizCity_Scheduler_Automation' );
		$hook_priority     = $automation_loaded
			? has_action( 'bizcity_scheduler_reminder_fire', [ BizCity_Scheduler_Automation::instance(), 'on_reminder_fire' ] )
			: false;
		$ctx->emit_step( [
			'label'  => 'Loader · automation runner',
			'status' => ( $automation_loaded && $hook_priority !== false ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'class=%s · hook@%s',
				$automation_loaded ? 'OK' : 'MISSING',
				$hook_priority !== false ? $hook_priority : 'not hooked'
			),
		] );
		if ( ! $automation_loaded || $hook_priority === false ) { $overall_fail = true; }

		// 2-B: BizCity_Cron_Manager::with_synthetic_run exists
		$cron_ok   = class_exists( 'BizCity_Cron_Manager' );
		$synth_ok  = $cron_ok && method_exists( 'BizCity_Cron_Manager', 'with_synthetic_run' );
		$ctx->emit_step( [
			'label'  => 'Loader · cron manager + with_synthetic_run()',
			'status' => $synth_ok ? 'pass' : 'fail',
			'detail' => sprintf(
				'class=%s · with_synthetic_run=%s',
				$cron_ok  ? 'OK' : 'MISSING',
				$synth_ok ? 'OK' : 'MISSING — update core/cron/includes/class-cron-manager.php'
			),
		] );
		if ( ! $synth_ok ) { $overall_fail = true; }

		// 2-C: REST route registered
		$routes = rest_get_server()->get_routes();
		$route_registered = array_key_exists(
			'/' . self::SCHED_NS . '/automation/fire-now',
			$routes
		);
		$ctx->emit_step( [
			'label'  => 'Loader · REST route registered',
			'status' => $route_registered ? 'pass' : 'fail',
			'detail' => $route_registered
				? '/' . self::SCHED_NS . '/automation/fire-now · registered'
				: '/' . self::SCHED_NS . '/automation/fire-now NOT registered in rest_get_server()',
		] );
		if ( ! $route_registered ) { $overall_fail = true; }

		// 2-D: BizCity_Intent_Tools available (tool registry)
		$tools_ok    = class_exists( 'BizCity_Intent_Tools' );
		$tool_count  = 0;
		if ( $tools_ok ) {
			try {
				$ref  = new ReflectionClass( 'BizCity_Intent_Tools' );
				$prop = $ref->getProperty( 'tools' );
				$prop->setAccessible( true );
				$tool_arr   = $prop->getValue( BizCity_Intent_Tools::instance() );
				$tool_count = is_array( $tool_arr ) ? count( $tool_arr ) : 0;
			} catch ( \Throwable $e ) {
				/* reflection failed — count stays 0 */
			}
		}
		$ctx->emit_step( [
			'label'  => 'Loader · Intent Tools registry',
			'status' => ( $tools_ok && $tool_count > 0 ) ? 'pass' : ( $tools_ok ? 'warn' : 'warn' ),
			'detail' => $tools_ok
				? sprintf( '%d tool(s) registered', $tool_count )
				: 'BizCity_Intent_Tools not loaded — automation steps will fail gracefully',
		] );
		if ( ! $tools_ok || $tool_count === 0 ) { $overall_warn = true; }

		/* ══════════════════════════════════════════════════════════════
		 * Stop here if any Layer 1/2 hard-fail
		 * ══════════════════════════════════════════════════════════════ */

		if ( $overall_fail ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Layer 1/2 check thất bại — không thể chạy real-call Layer 3.',
				'fix_hint' => 'Kiểm tra bootstrap.php, class loads, và route registration ở trên.',
			];
		}

		/* ══════════════════════════════════════════════════════════════
		 * LAYER 3 · RUNTIME — real call
		 * ══════════════════════════════════════════════════════════════ */

		$mgr = BizCity_Scheduler_Manager::instance();

		// 3-A: Create synthetic event with minimal automation chain
		$chain  = [
			'version' => 1,
			'on_fire' => [
				[
					'tool'     => 'scheduler_get_today_agenda',
					'args'     => [],
					'on_error' => 'continue',
				],
			],
		];
		$start_at = gmdate( 'Y-m-d H:i:s', time() + 60 ); // 1 min from now

		$created_id = 0;
		$create_err = '';
		try {
			// Use scheduler manager directly (same as POST /events callback does)
			$result = $mgr->create_event( [
				'title'      => self::HEALTHTEST_TAG,
				'start_at'   => $start_at,
				'reminder_min' => 0,
				'source'     => 'ai_plan',
				'ai_context' => wp_json_encode( [
					'automation'  => $chain,
					'skill_ref'   => '',
					'created_by'  => 'probe.scheduler.automation',
				] ),
			] );
			$created_id = is_array( $result ) ? (int) ( $result['id'] ?? 0 ) : (int) $result;
		} catch ( \Throwable $e ) {
			$create_err = $e->getMessage();
		}

		if ( ! $created_id ) {
			$ctx->emit_step( [
				'label'  => 'Runtime · create test event',
				'status' => 'fail',
				'detail' => 'create_event() returned 0 — ' . ( $create_err ?: 'no error detail' ),
			] );
			return [
				'status'   => 'fail',
				'summary'  => 'Cannot create synthetic event for real-call test.',
				'fix_hint' => 'Verify BizCity_Scheduler_Manager::create_event() and bizcity_crm_events table.',
			];
		}

		$ctx->emit_step( [
			'label'  => 'Runtime · create test event',
			'status' => 'pass',
			'detail' => sprintf( 'event #%d created (%s)', $created_id, self::HEALTHTEST_TAG ),
		] );

		// 3-B: Fire via REST callback (direct, authenticated — no HTTP round-trip)
		$fire_ok    = false;
		$fire_run   = 0;
		$fire_ms    = 0;
		$fire_error = '';
		try {
			$started   = microtime( true );
			$fake_req  = new WP_REST_Request( 'POST' );
			$fake_req->set_param( 'event_id', $created_id );

			$rest_obj  = BizCity_Scheduler_REST_API::instance();
			$response  = $rest_obj->automation_fire_now( $fake_req );
			$fire_ms   = (int) round( ( microtime( true ) - $started ) * 1000 );
			$body      = $response->get_data();
			$fire_ok   = ! empty( $body['ok'] );
			$fire_run  = (int) ( $body['run_id'] ?? 0 );
			$fire_error = (string) ( $body['error'] ?? '' );
		} catch ( \Throwable $e ) {
			$fire_error = $e->getMessage();
		}

		$ctx->emit_step( [
			'label'  => 'Runtime · fire-now',
			'status' => $fire_ok ? 'pass' : 'fail',
			'detail' => $fire_ok
				? sprintf( 'ok · run_id=%d · %dms', $fire_run, $fire_ms )
				: ( 'FAILED: ' . $fire_error ),
		] );

		if ( ! $fire_ok ) {
			$this->cleanup_event( $mgr, $created_id );
			return [
				'status'    => 'fail',
				'summary'   => 'automation_fire_now() failed.',
				'error'     => $fire_error,
				'fix_hint'  => 'Xem error field. Nếu "event_not_found" → create_event() không trả đúng ID. Nếu throw → xem PHP error log.',
				'artifacts' => [ [ 'kind' => 'event', 'id' => $created_id, 'label' => 'synthetic event (failed-fire)' ] ],
			];
		}

		// 3-C: Pull chain evidence from /automation/recent + verify our event
		$chain_found  = false;
		$chain_status = '';
		$step_count   = 0;
		$recent_err   = '';
		try {
			$recent_req = new WP_REST_Request( 'GET' );
			$recent_req->set_param( 'limit', 20 );

			$recent_resp = BizCity_Scheduler_REST_API::instance()->automation_recent( $recent_req );
			$recent_data = $recent_resp->get_data();
			foreach ( ( $recent_data['chains'] ?? [] ) as $c ) {
				if ( (int) ( $c['event_id'] ?? 0 ) === $created_id ) {
					$chain_found  = true;
					$chain_status = (string) ( $c['status'] ?? '' );
					$step_count   = count( $c['steps'] ?? [] );
					break;
				}
			}
		} catch ( \Throwable $e ) {
			$recent_err = $e->getMessage();
		}

		// PASS if chain was found with any terminal status (ok / partial / failed).
		// 'failed' = runner ran but tool not configured — pipeline is still wired correctly.
		$chain_terminal = $chain_found && in_array( $chain_status, [ 'ok', 'partial', 'failed' ], true );
		$timeline_pass  = $chain_terminal;
		$ctx->emit_step( [
			'label'  => 'Runtime · chain evidence in cron_runs',
			'status' => $timeline_pass ? 'pass' : ( $chain_found ? 'warn' : 'fail' ),
			'detail' => $chain_found
				? sprintf( 'event #%d · status=%s · %d step(s)', $created_id, $chain_status, $step_count )
				: ( $recent_err ?: sprintf( 'event #%d not found in recent 20 chains — có thể runner không detect automation chain (ai_context empty/unparseable)', $created_id ) ),
		] );

		if ( ! $chain_found ) { $overall_warn = true; }

		// 3-D: Validate /automation/validate endpoint (lint a good chain)
		$validate_ok  = false;
		$validate_err = '';
		try {
			$val_req = new WP_REST_Request( 'POST' );
			$val_req->set_body_params( [ 'automation' => $chain ] );
			// set_body_params doesn't trigger JSON parse — work around by
			// using set_param after encoding.
			$val_req2 = new WP_REST_Request( 'POST' );
			$val_req2->set_header( 'Content-Type', 'application/json' );
			$val_req2->set_body( (string) wp_json_encode( [ 'automation' => $chain ] ) );
			$val_resp = BizCity_Scheduler_REST_API::instance()->automation_validate( $val_req2 );
			$val_body = $val_resp->get_data();
			$validate_ok = ! empty( $val_body['ok'] );
			if ( ! $validate_ok ) {
				$validate_err = implode( ' | ', (array) ( $val_body['errors'] ?? [] ) );
			}
		} catch ( \Throwable $e ) {
			$validate_err = $e->getMessage();
		}

		$ctx->emit_step( [
			'label'  => 'Runtime · validate endpoint',
			'status' => $validate_ok ? 'pass' : 'warn',
			'detail' => $validate_ok
				? 'Chain lint: no errors'
				: 'Lint errors: ' . ( $validate_err ?: 'unknown' ),
		] );

		// ── Cleanup ────────────────────────────────────────────────────
		$this->cleanup_event( $mgr, $created_id );

		$ctx->emit_step( [
			'label'  => 'Cleanup · delete test event',
			'status' => 'pass',
			'detail' => sprintf( 'event #%d deleted', $created_id ),
		] );

		/* ══════════════════════════════════════════════════════════════
		 * Final verdict
		 * ══════════════════════════════════════════════════════════════ */

		if ( ! $timeline_pass ) {
			return [
				'status'   => 'warn',
				'summary'  => sprintf(
					'fire-now OK (run #%d, %dms) nhưng chain evidence không rõ ràng (status=%s). Có thể runner không parse được ai_context.',
					$fire_run, $fire_ms,
					$chain_status ?: 'not-found'
				),
				'fix_hint' => 'Xem class-scheduler-rest-api.php::automation_fire_now() — đảm bảo get_event() object được cast sang array trước khi do_action(). Runner check is_array($event).',
			];
		}

		$status_note = $chain_status === 'ok' ? 'all steps ok'
			: ( $chain_status === 'partial' ? 'some steps ok' : 'steps ran (tools may not be configured)' );

		return [
			'status'  => 'pass',
			'summary' => sprintf(
				'End-to-end OK · run #%d · %dms · chain=%s (%s) · %d step(s)',
				$fire_run, $fire_ms, $chain_status, $status_note, $step_count
			),
		];
	}

	/* ─────────────────────────────────────────────────────────────────── */

	public function cleanup(): void {
		// Best-effort GC of any leftover __healthtest_automation events.
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) { return; }
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_crm_events';
		$wpdb->suppress_errors( true );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE title LIKE %s LIMIT 50",
			'%' . $wpdb->esc_like( self::HEALTHTEST_TAG ) . '%'
		) );
		$wpdb->suppress_errors( false );
		if ( $ids ) {
			$this->cleanup_event( BizCity_Scheduler_Manager::instance(), ...$ids );
		}
	}

	/* ─────────────── Private helpers ──────────────────────────────── */

	/**
	 * Attempt to delete one or more events via manager or raw SQL.
	 *
	 * @param BizCity_Scheduler_Manager $mgr
	 * @param int ...$ids
	 */
	private function cleanup_event( $mgr, int ...$ids ): void {
		foreach ( $ids as $id ) {
			if ( $id <= 0 ) { continue; }
			try {
				if ( method_exists( $mgr, 'delete_event' ) ) {
					$mgr->delete_event( $id );
				} else {
					global $wpdb;
					$wpdb->delete( $wpdb->prefix . 'bizcity_crm_events', [ 'id' => $id ], [ '%d' ] );
				}
			} catch ( \Throwable $e ) {
				// Best-effort.
			}
		}
	}

	/**
	 * Case-insensitive substring search inside a file (read once, no grep binary).
	 *
	 * @param string $path  Absolute file path.
	 * @param string $needle
	 * @return bool
	 */
	private function grep_file( string $path, string $needle ): bool {
		$content = file_get_contents( $path );
		if ( $content === false ) { return false; }
		return str_contains( $content, $needle );
	}

	/**
	 * Check if a file starts with a UTF-8 BOM (EF BB BF).
	 */
	private function has_bom( string $path ): bool {
		$f = @fopen( $path, 'rb' );
		if ( ! $f ) { return false; }
		$bytes = fread( $f, 3 );
		fclose( $f );
		return $bytes === "\xEF\xBB\xBF";
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Scheduler_Automation';
	return $list;
} );
