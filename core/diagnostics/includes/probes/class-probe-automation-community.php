<?php
/**
 * BizCity Diagnostics — automation.community_gallery probe (Wave E · W7).
 *
 * R-DDV — verify Community Gallery PoC:
 *   1. Disk    — class-automation-community.php readable.
 *   2. Loader  — BizCity_Automation_Community class loaded.
 *   3. Allowlist — `validate_url('https://raw.githubusercontent.com/foo/bar.md')` → true.
 *   4. SSRF block #1 — non-HTTPS rejected.
 *   5. SSRF block #2 — non-allowlisted host rejected (e.g. `evil.example.com`).
 *   6. SSRF block #3 — path traversal `..` rejected.
 *   7. REST route GET /community/workflows registered.
 *   8. REST route GET /community/workflow registered.
 *   9. REST route POST /community/workflows/import registered.
 *
 * All steps are read-only — no DB writes, no external HTTP calls.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      Wave E (2026-06-03)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Automation_Community', false ) ) {
	return;
}

final class BizCity_Probe_Automation_Community implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'automation.community_gallery'; }
	public function label(): string       { return 'Automation · Community Gallery (WF-AUTO W7)'; }
	public function description(): string {
		return 'Verify Community Gallery PoC: service class loaded, URL allowlist + SSRF guards working, 3 REST routes registered.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 41; }
	public function icon(): string        { return 'cloud-upload'; }
	public function estimate_ms(): int    { return 200; }

	public function precondition() {
		// [2026-06-03 Johnny Chu] WF-AUTO W7 probe — required classes.
		foreach ( array( 'BizCity_Automation_Community', 'BizCity_Automation_REST' ) as $cls ) {
			if ( ! class_exists( $cls ) ) {
				return new WP_Error( 'class_missing', $cls . ' chưa load.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-06-03 Johnny Chu] WF-AUTO W7 probe — 9 read-only assertions.
		$steps  = array();
		$all_ok = true;

		// Step 1: Disk.
		$disk_path = dirname( __DIR__, 4 ) . '/automation/includes/class-automation-community.php';
		$disk_ok   = is_readable( $disk_path );
		$steps[]   = $s = array(
			'label'  => 'Disk: class-automation-community.php readable',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? $disk_path : 'NOT found at ' . $disk_path,
		);
		$ctx->emit_step( $s );
		if ( ! $disk_ok ) { $all_ok = false; }

		// Step 2: Loader.
		$loader_ok = class_exists( 'BizCity_Automation_Community' );
		$steps[]   = $s = array(
			'label'  => 'Loader: BizCity_Automation_Community class_exists',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'OK' : 'class NOT loaded — check core/automation/bootstrap.php',
		);
		$ctx->emit_step( $s );
		if ( ! $loader_ok ) {
			return $this->build_result( $steps, false );
		}

		$svc = BizCity_Automation_Community::instance();

		// Step 3: Allowlist accepts canonical GitHub raw.
		$ok_url    = 'https://raw.githubusercontent.com/foo/bar/main/x.workflow.md';
		$ok_result = $svc->validate_url( $ok_url );
		$ok_pass   = $ok_result === true;
		$steps[]   = $s = array(
			'label'  => 'validate_url() accepts raw.githubusercontent.com HTTPS',
			'status' => $ok_pass ? 'pass' : 'fail',
			'detail' => $ok_pass ? 'OK' : ( is_wp_error( $ok_result ) ? $ok_result->get_error_code() . ': ' . $ok_result->get_error_message() : 'unexpected non-true result' ),
		);
		$ctx->emit_step( $s );
		if ( ! $ok_pass ) { $all_ok = false; }

		// Step 4: SSRF — non-HTTPS rejected.
		$bad1     = $svc->validate_url( 'http://raw.githubusercontent.com/foo/bar.md' );
		$bad1_ok  = is_wp_error( $bad1 ) && $bad1->get_error_code() === 'url_not_https';
		$steps[]  = $s = array(
			'label'  => 'SSRF guard: non-HTTPS rejected (url_not_https)',
			'status' => $bad1_ok ? 'pass' : 'fail',
			'detail' => $bad1_ok ? 'OK — http:// blocked' : 'http:// NOT blocked properly',
		);
		$ctx->emit_step( $s );
		if ( ! $bad1_ok ) { $all_ok = false; }

		// Step 5: SSRF — host not in allowlist.
		$bad2    = $svc->validate_url( 'https://evil.example.com/payload.md' );
		$bad2_ok = is_wp_error( $bad2 ) && $bad2->get_error_code() === 'url_host_not_allowed';
		$steps[] = $s = array(
			'label'  => 'SSRF guard: non-allowlisted host rejected (url_host_not_allowed)',
			'status' => $bad2_ok ? 'pass' : 'fail',
			'detail' => $bad2_ok ? 'OK — evil.example.com blocked' : 'arbitrary host NOT blocked',
		);
		$ctx->emit_step( $s );
		if ( ! $bad2_ok ) { $all_ok = false; }

		// Step 6: SSRF — path traversal.
		$bad3    = $svc->validate_url( 'https://raw.githubusercontent.com/foo/../etc/passwd' );
		$bad3_ok = is_wp_error( $bad3 ) && $bad3->get_error_code() === 'url_path_traversal';
		$steps[] = $s = array(
			'label'  => 'SSRF guard: path traversal ".." rejected',
			'status' => $bad3_ok ? 'pass' : 'fail',
			'detail' => $bad3_ok ? 'OK — ".." blocked' : '".." NOT blocked',
		);
		$ctx->emit_step( $s );
		if ( ! $bad3_ok ) { $all_ok = false; }

		// Step 7-9: REST routes registered.
		$routes = rest_get_server()->get_routes();
		$want   = array(
			'/bizcity-automation/v1/community/workflows'        => 'GET …/community/workflows',
			'/bizcity-automation/v1/community/workflow'         => 'GET …/community/workflow',
			'/bizcity-automation/v1/community/workflows/import' => 'POST …/community/workflows/import',
		);
		foreach ( $want as $pattern => $label ) {
			$present = isset( $routes[ $pattern ] );
			$steps[] = $s = array(
				'label'  => 'REST route: ' . $label . ' registered',
				'status' => $present ? 'pass' : 'fail',
				'detail' => $present ? 'Route present' : 'Route MISSING — check register_routes()',
			);
			$ctx->emit_step( $s );
			if ( ! $present ) { $all_ok = false; }
		}

		return $this->build_result( $steps, $all_ok );
	}

	public function cleanup(): void {
		// Read-only probe — nothing to clean up.
	}

	/**
	 * @param array $steps
	 * @param bool  $all_ok
	 * @return array
	 */
	private function build_result( array $steps, $all_ok ): array {
		if ( $all_ok ) {
			return array(
				'status'  => 'pass',
				'summary' => 'Community Gallery: service loaded · allowlist OK · 3 SSRF guards firing · 3 REST routes registered.',
				'steps'   => $steps,
			);
		}
		return array(
			'status'  => 'fail',
			'summary' => 'Community Gallery probe gặp lỗi — xem steps bên dưới.',
			'steps'   => $steps,
		);
	}
}
