<?php
/**
 * [2026-06-07 Johnny Chu] PHASE-0.40 G3.4 + G4.5 — DDV probe: CRM BizCity parity (reports + campaign variants)
 *
 * Probe ID  : core.crm.bizcity_parity
 * Order     : 44
 * 3 layers:
 *   Disk    : 6 report callbacks + broadcast dispatcher file exist
 *   Loader  : classes + crmApi hooks exist
 *   Runtime : GET /reports/message returns 200 + total key
 *
 * @package BizCity_Twin_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

// [2026-07-02 Johnny Chu] HOTFIX — add missing interface methods (description/severity/icon/estimate_ms) + fix run() return type
if ( class_exists( 'BizCity_Probe_CRM_BizCity_Parity', false ) ) {
	return;
}

final class BizCity_Probe_CRM_BizCity_Parity implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.crm.bizcity_parity'; }
	public function label(): string       { return 'CRM BizCity Parity (reports + variants)'; }
	public function description(): string { return 'Kiểm tra 6 report callbacks + broadcast dispatcher + pick_variant() tồn tại và có thể gọi được.'; }
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 44; }
	public function icon(): string        { return 'bar-chart-2'; }
	public function estimate_ms(): int    { return 3000; }
	public function tags(): array         { return array( 'crm', 'reports', 'bizcity', 'campaign' ); }

	/**
	 * @return bool
	 */
	public function precondition() {
		return class_exists( 'BizCity_CRM_DB_Installer_V2' );
	}

	/**
	 * @param object $ctx
	 * @return array
	 */
	public function run( $ctx ): array {
		$results = array();

		// ── Layer 1: Disk ──
		$rest_file = dirname( __FILE__, 3 ) . '/../../plugins/bizcity-twin-crm/includes/class-rest-controller.php';
		$disp_file = dirname( __FILE__, 3 ) . '/../../plugins/bizcity-twin-crm/includes/campaigns/class-broadcast-dispatcher.php';

		$methods_to_check = array(
			'get_reports_message',
			'get_reports_response',
			'get_reports_agent',
			'get_reports_campaign',
			'get_reports_workflow',
			'get_reports_ai',
		);

		$rest_src  = file_exists( $rest_file ) ? file_get_contents( $rest_file ) : '';
		$disp_src  = file_exists( $disp_file ) ? file_get_contents( $disp_file ) : '';
		$disk_ok   = true;
		$disk_msgs = array();
		foreach ( $methods_to_check as $m ) {
			if ( strpos( $rest_src, "function {$m}" ) === false ) {
				$disk_ok   = false;
				$disk_msgs[] = "Missing: {$m}";
			}
		}
		if ( ! file_exists( $disp_file ) ) {
			$disk_ok   = false;
			$disk_msgs[] = 'Missing: class-broadcast-dispatcher.php';
		}
		if ( strpos( $disp_src, 'pick_variant' ) === false ) {
			$disk_ok   = false;
			$disk_msgs[] = 'Missing: pick_variant() in broadcast dispatcher';
		}

		$results[] = array(
			'layer'  => 'Disk',
			'status' => $disk_ok ? 'PASS' : 'FAIL',
			'note'   => $disk_ok ? '6 report callbacks + broadcast dispatcher detected' : implode( '; ', $disk_msgs ),
		);

		// ── Layer 2: Loader ──
		$rest_loaded = class_exists( 'BizCity_CRM_REST_Controller' );
		$disp_loaded = class_exists( 'BizCity_CRM_Broadcast_Dispatcher' );

		$loader_ok   = $rest_loaded && $disp_loaded;
		$loader_msgs = array();
		if ( ! $rest_loaded ) { $loader_msgs[] = 'BizCity_CRM_REST_Controller not loaded'; }
		if ( ! $disp_loaded ) { $loader_msgs[] = 'BizCity_CRM_Broadcast_Dispatcher not loaded'; }

		$results[] = array(
			'layer'  => 'Loader',
			'status' => $loader_ok ? 'PASS' : 'FAIL',
			'note'   => $loader_ok ? 'REST controller + broadcast dispatcher classes loaded' : implode( '; ', $loader_msgs ),
		);

		// ── Layer 3: Runtime ──
		$runtime_ok = false;
		$runtime_msg = '';
		do {
			if ( ! $rest_loaded ) {
				$runtime_msg = 'REST controller not loaded — skip';
				$results[] = array( 'layer' => 'Runtime', 'status' => 'SKIP', 'note' => $runtime_msg );
				break;
			}
			// Simulate what the report callback does — check no fatal.
			try {
				$req = new WP_REST_Request( 'GET', '/bizcity-crm/v1/reports/message' );
				$req->set_query_params( array( 'days' => '1' ) );
				$response = BizCity_CRM_REST_Controller::get_reports_message( $req );
				if ( is_a( $response, 'WP_Error' ) ) {
					$runtime_msg = 'WP_Error: ' . $response->get_error_message();
					$runtime_ok  = false;
				} elseif ( is_a( $response, 'WP_REST_Response' ) ) {
					$body = $response->get_data();
					$runtime_ok  = isset( $body['data']['total'] ) || isset( $body['total'] );
					$runtime_msg = $runtime_ok ? 'GET /reports/message returned total key' : 'Missing total key in response';
				} elseif ( is_array( $response ) ) {
					$runtime_ok  = array_key_exists( 'total', $response );
					$runtime_msg = $runtime_ok ? 'GET /reports/message returned total key' : 'Missing total key in direct array response';
				} else {
					$runtime_msg = 'Unexpected response type: ' . gettype( $response );
				}
			} catch ( Exception $e ) {
				$runtime_msg = 'Exception: ' . $e->getMessage();
			}
			$results[] = array(
				'layer'  => 'Runtime',
				'status' => $runtime_ok ? 'PASS' : 'FAIL',
				'note'   => $runtime_msg,
			);
		} while ( false );

		$all_pass = empty( array_filter( $results, static function ( $r ) {
			return $r['status'] === 'FAIL';
		} ) );

		return array(
			'status'  => $all_pass ? 'PASS' : 'FAIL',
			'layers'  => $results,
			'summary' => $all_pass
				? 'G3+G4 BizCity parity: reports + variants all checks pass.'
				: 'One or more BizCity parity checks failed.',
		);
	}

	public function cleanup(): void {}
}

// Self-register
add_filter( 'bizcity_diagnostics_register_probes', static function ( array $probes ) {
	$probes[] = new BizCity_Probe_CRM_BizCity_Parity();
	return $probes;
} );
