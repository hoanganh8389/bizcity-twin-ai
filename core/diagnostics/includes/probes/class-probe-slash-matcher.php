<?php
/**
 * BizCity Diagnostics — automation.slash_matcher probe.
 *
 * R-DDV (Diagnostic-Driven Validation) — verify Wave D (WF-AUTO W4/W5/W6):
 *
 *   1. **Disk** — class-skill-slash-matcher.php tồn tại và readable.
 *   2. **Loader** — BizCity_Skill_Slash_Matcher class loaded.
 *   3. **extract_command unit** — blank / no-slash / bare-slash → null;
 *      "/kg some args" → {cmd:'kg', args:'some args'};
 *      "/" + str_repeat('a', 65) → null (W5 len guard).
 *   4. **detect_collision empty** — empty list returns null.
 *   5. **try_dispatch plain text** — "hello world" → matched:false.
 *   6. **TRIGGER_TYPES vocab** — "slash_command" in TRIGGER_TYPES constant.
 *   7. **Canvas export route** — GET /bizcity-automation/v1/workflows/…/export-md registered.
 *   8. **Canvas import route** — POST /bizcity-automation/v1/workflows/import-md registered.
 *
 * All steps are read-only / unit assertions — no DB writes.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      Wave D (2026-06-03)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Slash_Matcher', false ) ) {
	return;
}

final class BizCity_Probe_Slash_Matcher implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'automation.slash_matcher'; }
	public function label(): string       { return 'Automation · Slash Matcher (GURU W2/W3/W6)'; }
	public function description(): string {
		return 'Verify Wave D: dual-tier /cmd dispatch, W5 hardening (len guard + dedup), canvas import/export REST routes registered.';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 40; }
	public function icon(): string        { return 'admin-tools'; }
	public function estimate_ms(): int    { return 400; }

	public function precondition() {
		// [2026-06-03 Johnny Chu] WF-AUTO probe — guard required classes.
		foreach ( array(
			'BizCity_Skill_Slash_Matcher',
			'BizCity_Automation_Repo_Workflows',
			'BizCity_Automation_REST',
		) as $cls ) {
			if ( ! class_exists( $cls ) ) {
				return new WP_Error( 'class_missing', $cls . ' chưa load.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-06-03 Johnny Chu] WF-AUTO probe — 8 read-only steps (R-DDV).
		$steps  = array();
		$all_ok = true;

		// ── Step 1: Disk ───────────────────────────────────────────────
		$disk_path = dirname( __DIR__, 4 ) . '/skills/includes/class-skill-slash-matcher.php';
		$disk_ok   = is_readable( $disk_path );
		$steps[]   = $s = array(
			'label'  => 'Disk: class-skill-slash-matcher.php readable',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? $disk_path : 'NOT found at ' . $disk_path,
		);
		$ctx->emit_step( $s );
		if ( ! $disk_ok ) { $all_ok = false; }

		// ── Step 2: Loader ─────────────────────────────────────────────
		$loader_ok = class_exists( 'BizCity_Skill_Slash_Matcher' );
		$steps[]   = $s = array(
			'label'  => 'Loader: BizCity_Skill_Slash_Matcher class_exists',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'OK' : 'class NOT loaded — check core/skills/bootstrap.php',
		);
		$ctx->emit_step( $s );
		if ( ! $loader_ok ) {
			// Cannot run further steps.
			$all_ok = false;
			return $this->build_result( $steps, $all_ok );
		}

		// ── Step 3: extract_command unit tests ─────────────────────────
		$ec_cases = array(
			array( 'input' => '',            'expect_null' => true,  'label' => 'blank → null' ),
			array( 'input' => 'hello world', 'expect_null' => true,  'label' => 'no slash → null' ),
			array( 'input' => '/',           'expect_null' => true,  'label' => 'bare / → null' ),
			array( 'input' => '/kg some args','expect_null' => false, 'label' => '/kg → {cmd:kg, args:some args}',
				'expect_cmd' => 'kg', 'expect_args' => 'some args' ),
			array( 'input' => '/' . str_repeat( 'a', 65 ), 'expect_null' => true, 'label' => '/ + 65-char slug → null (W5 guard)' ),
		);
		$ec_fail_details = array();
		foreach ( $ec_cases as $c ) {
			$result = BizCity_Skill_Slash_Matcher::extract_command( $c['input'] );
			if ( $c['expect_null'] ) {
				if ( $result !== null ) {
					$ec_fail_details[] = $c['label'] . ' — expected null, got ' . wp_json_encode( $result );
				}
			} else {
				if ( $result === null ) {
					$ec_fail_details[] = $c['label'] . ' — expected array, got null';
				} elseif ( isset( $c['expect_cmd'] ) && $result['cmd'] !== $c['expect_cmd'] ) {
					$ec_fail_details[] = $c['label'] . ' — cmd=' . $result['cmd'] . ' want ' . $c['expect_cmd'];
				} elseif ( isset( $c['expect_args'] ) && $result['args'] !== $c['expect_args'] ) {
					$ec_fail_details[] = $c['label'] . ' — args=' . $result['args'] . ' want ' . $c['expect_args'];
				}
			}
		}
		$ec_ok   = empty( $ec_fail_details );
		$steps[] = $s = array(
			'label'  => 'extract_command unit tests (' . count( $ec_cases ) . ' cases)',
			'status' => $ec_ok ? 'pass' : 'fail',
			'detail' => $ec_ok ? 'All cases OK' : implode( ' | ', $ec_fail_details ),
		);
		$ctx->emit_step( $s );
		if ( ! $ec_ok ) { $all_ok = false; }

		// ── Step 4: detect_collision empty list ────────────────────────
		$dc_result = BizCity_Skill_Slash_Matcher::instance()->detect_collision( array(), 'skill', 0 );
		$dc_ok     = $dc_result === null;
		$steps[]   = $s = array(
			'label'  => 'detect_collision( [], skill, 0 ) → null',
			'status' => $dc_ok ? 'pass' : 'fail',
			'detail' => $dc_ok ? 'OK — null (no collision)' : 'Unexpected: ' . wp_json_encode( $dc_result ),
		);
		$ctx->emit_step( $s );
		if ( ! $dc_ok ) { $all_ok = false; }

		// ── Step 5: try_dispatch plain text → matched:false ────────────
		$td_result = BizCity_Skill_Slash_Matcher::instance()->try_dispatch( array(), 'hello world' );
		$td_ok     = isset( $td_result['matched'] ) && $td_result['matched'] === false;
		$steps[]   = $s = array(
			'label'  => 'try_dispatch "hello world" → matched:false',
			'status' => $td_ok ? 'pass' : 'fail',
			'detail' => $td_ok ? 'matched=false (no slash prefix)' : 'Got: ' . wp_json_encode( $td_result ),
		);
		$ctx->emit_step( $s );
		if ( ! $td_ok ) { $all_ok = false; }

		// ── Step 6: TRIGGER_TYPES vocab ────────────────────────────────
		$vocab_ok = false;
		if ( defined( 'BizCity_Automation_Repo_Workflows::TRIGGER_TYPES' ) ) {
			$types    = BizCity_Automation_Repo_Workflows::TRIGGER_TYPES;
			$vocab_ok = is_array( $types ) && in_array( 'slash_command', $types, true );
		}
		$steps[] = $s = array(
			'label'  => 'BizCity_Automation_Repo_Workflows::TRIGGER_TYPES has "slash_command"',
			'status' => $vocab_ok ? 'pass' : 'fail',
			'detail' => $vocab_ok ? '"slash_command" present in TRIGGER_TYPES' : '"slash_command" MISSING — check class-automation-repo-workflows.php',
		);
		$ctx->emit_step( $s );
		if ( ! $vocab_ok ) { $all_ok = false; }

		// ── Step 7: Canvas export route registered ─────────────────────
		$routes          = rest_get_server()->get_routes();
		$export_pattern  = '/bizcity-automation/v1/workflows/(?P<id>\d+)/export-md';
		$export_route_ok = isset( $routes[ $export_pattern ] );
		$steps[]         = $s = array(
			'label'  => 'REST route: GET …/workflows/:id/export-md registered',
			'status' => $export_route_ok ? 'pass' : 'fail',
			'detail' => $export_route_ok ? 'Route present' : 'Route MISSING — check register_routes() in class-automation-rest.php',
		);
		$ctx->emit_step( $s );
		if ( ! $export_route_ok ) { $all_ok = false; }

		// ── Step 8: Canvas import route registered ─────────────────────
		$import_pattern  = '/bizcity-automation/v1/workflows/import-md';
		$import_route_ok = isset( $routes[ $import_pattern ] );
		$steps[]         = $s = array(
			'label'  => 'REST route: POST …/workflows/import-md registered',
			'status' => $import_route_ok ? 'pass' : 'fail',
			'detail' => $import_route_ok ? 'Route present' : 'Route MISSING — check register_routes() in class-automation-rest.php',
		);
		$ctx->emit_step( $s );
		if ( ! $import_route_ok ) { $all_ok = false; }

		return $this->build_result( $steps, $all_ok );
	}

	public function cleanup(): void {
		// Read-only probe — nothing to clean up.
	}

	// ─── private helpers ─────────────────────────────────────────────────

	/**
	 * @param array $steps
	 * @param bool  $all_ok
	 * @return array
	 */
	private function build_result( array $steps, $all_ok ): array {
		if ( $all_ok ) {
			return array(
				'status'  => 'pass',
				'summary' => 'Slash Matcher: disk + loader + extract_command unit tests + collision + try_dispatch + vocab + canvas routes all OK.',
				'steps'   => $steps,
			);
		}
		return array(
			'status'  => 'fail',
			'summary' => 'Slash Matcher probe gặp lỗi — xem steps bên dưới.',
			'steps'   => $steps,
		);
	}
}
