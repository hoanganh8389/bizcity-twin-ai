<?php
/**
 * DDV Probe — Automation Template Hub (Branch #17)
 *
 * 3-layer evidence:
 *   Disk    : BizCity_Automation_Hub_Client class file exists.
 *   Loader  : Class loaded + is_hub_ready() returns true.
 *   Runtime : browse([]) returns non-empty items array from Hub.
 *
 * [2026-06-16 Johnny Chu] PHASE-ATH W5 — probe for hub client readiness.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Probe_Hub_Templates {

	/**
	 * Probe identifier (matches row in diagnostics page).
	 */
	const PROBE_ID = 'core.automation.hub_templates';

	/**
	 * Run all 3 layers and return combined result.
	 *
	 * @return array{ probe_id:string, status:string, layers:array, summary:string }
	 */
	public static function run() {
		$layers = array();

		// ── Layer 1: Disk ────────────────────────────────────────────────
		$client_file = defined( 'BIZCITY_TWIN_AI_DIR' )
			? BIZCITY_TWIN_AI_DIR . 'core/automation/includes/class-automation-hub-client.php'
			: '';
		$disk_ok = $client_file && file_exists( $client_file );
		$layers[] = array(
			'layer'   => 'Disk',
			'status'  => $disk_ok ? 'PASS' : 'FAIL',
			'detail'  => $disk_ok
				? 'class-automation-hub-client.php tồn tại.'
				: 'Không tìm thấy class-automation-hub-client.php.',
		);

		// ── Layer 2: Loader ──────────────────────────────────────────────
		$class_ok  = class_exists( 'BizCity_Automation_Hub_Client' );
		$ready_ok  = false;
		if ( $class_ok ) {
			$hub      = BizCity_Automation_Hub_Client::instance();
			// Call private is_hub_ready indirectly: browse() returns _degraded=false only if ready.
			$probe_r  = $hub->browse( array( 'per_page' => 1 ) );
			$ready_ok = isset( $probe_r['_degraded'] ) && ! $probe_r['_degraded'];
		}

		$loader_status = ( $class_ok && $ready_ok ) ? 'PASS' : ( $class_ok ? 'FAIL' : 'FAIL' );
		$loader_detail = '';
		if ( ! $class_ok ) {
			$loader_detail = 'BizCity_Automation_Hub_Client không load được.';
		} elseif ( ! $ready_ok ) {
			$loader_detail = 'BizCity_Automation_Hub_Client loaded nhưng is_hub_ready()=false. '
				. 'Kiểm tra BizCity_LLM_Client + API Key tại Cài đặt → BizCity → API Key.';
		} else {
			$loader_detail = 'BizCity_Automation_Hub_Client loaded, is_hub_ready()=true.';
		}

		$layers[] = array(
			'layer'  => 'Loader',
			'status' => $loader_status,
			'detail' => $loader_detail,
		);

		// ── Layer 3: Runtime ─────────────────────────────────────────────
		$runtime_status = 'SKIP';
		$runtime_detail = 'Bỏ qua — Hub chưa ready (Loader FAIL).';

		if ( $class_ok && $ready_ok ) {
			$hub  = BizCity_Automation_Hub_Client::instance();
			$resp = $hub->browse( array( 'per_page' => 3 ) );

			if ( isset( $resp['_degraded'] ) && $resp['_degraded'] ) {
				$runtime_status = 'FAIL';
				$msg            = isset( $resp['message'] ) ? (string) $resp['message'] : 'Hub trả _degraded=true.';
				$runtime_detail = 'Browse failed: ' . $msg;
			} elseif ( isset( $resp['rows'] ) && is_array( $resp['rows'] ) && count( $resp['rows'] ) > 0 ) {
				$runtime_status = 'PASS';
				$runtime_detail = 'Hub trả ' . count( $resp['rows'] ) . ' template(s). total=' . ( isset( $resp['total'] ) ? $resp['total'] : '?' );
			} else {
				$runtime_status = 'FAIL';
				$runtime_detail = 'Hub kết nối được nhưng không có template nào. Cần seed ít nhất 1 template.';
			}
		}

		$layers[] = array(
			'layer'  => 'Runtime',
			'status' => $runtime_status,
			'detail' => $runtime_detail,
		);

		// ── Aggregate ────────────────────────────────────────────────────
		$statuses = array_column( $layers, 'status' );
		if ( in_array( 'FAIL', $statuses, true ) ) {
			$overall = 'FAIL';
		} elseif ( in_array( 'SKIP', $statuses, true ) ) {
			$overall = 'SKIP';
		} else {
			$overall = 'PASS';
		}

		return array(
			'probe_id' => self::PROBE_ID,
			'status'   => $overall,
			'layers'   => $layers,
			'summary'  => 'Automation Hub Templates (Branch #17): ' . $overall,
		);
	}
}
