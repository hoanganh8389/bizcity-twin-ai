<?php
/**
 * BizCity Diagnostics — core.helper.error_ux probe (R-ERROR-UX · 2026-06-05)
 *
 * 3-layer DDV (R-DDV) cho R-ERROR-UX:
 *   - Disk:    class-bizcity-error-payload.php tồn tại.
 *   - Loader:  BizCity_Error_Payload class đã load.
 *   - Runtime: BizCity_Error_Payload::make() và from_wp_error() trả đúng 4 trường;
 *              wp_send_json_error() KHÔNG bị dùng trong các file channel-gateway
 *              (audit nhanh không có trong regex — đây là read-only scan).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-05 (R-ERROR-UX v1.0)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Error_UX', false ) ) {
	return;
}

final class BizCity_Probe_Error_UX implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.helper.error_ux'; }
	public function label(): string       { return 'R-ERROR-UX — Error Payload Helper'; }
	public function description(): string {
		return 'Kiểm tra BizCity_Error_Payload đã load + make()/from_wp_error() trả đúng 4 trường canonical. Đồng thời audit nhanh class-admin-menu.php còn dùng wp_send_json_error(string) không.';
	}
	public function severity(): string    { return 'info'; }
	public function order(): int          { return 5; }
	public function icon(): string        { return 'shield-check'; }
	public function estimate_ms(): int    { return 300; }

	public function precondition() {
		return true; // Không phụ thuộc external service.
	}

	public function run( $ctx ): array {
		$steps = array();

		// ── Step 1: Disk ────────────────────────────────────────────────────
		// [2026-07-10 Johnny Chu] R-ERROR-UX — resolve plugin root correctly (avoid false path /core/core/*).
		$plugin_root = defined( 'BIZCITY_TWIN_AI_DIR' )
			? BIZCITY_TWIN_AI_DIR
			: dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$file = $plugin_root . 'core/helper/includes/class-bizcity-error-payload.php';

		if ( file_exists( $file ) ) {
			$steps[] = array(
				'label'  => 'Disk — class-bizcity-error-payload.php tồn tại',
				'status' => 'pass',
			);
		} else {
			$steps[] = array(
				'label'  => 'Disk — class-bizcity-error-payload.php tồn tại',
				'status' => 'fail',
				'detail' => 'File không tồn tại: ' . $file,
			);
			return array(
				'status'   => 'fail',
				'summary'  => 'File helper không tồn tại. Chạy lại deploy.',
				'fix_hint' => 'Đảm bảo core/helper/includes/class-bizcity-error-payload.php đã được commit và deploy.',
				'steps'    => $steps,
			);
		}

		// ── Step 2: Loader ──────────────────────────────────────────────────
		if ( class_exists( 'BizCity_Error_Payload' ) ) {
			$steps[] = array(
				'label'  => 'Loader — class BizCity_Error_Payload đã load',
				'status' => 'pass',
			);
		} else {
			$steps[] = array(
				'label'  => 'Loader — class BizCity_Error_Payload đã load',
				'status' => 'fail',
				'detail' => 'Class chưa có trong memory. Kiểm tra bootstrap.php có require core/helper/bootstrap.php không.',
			);
			return array(
				'status'   => 'fail',
				'summary'  => 'BizCity_Error_Payload chưa load. Kiểm tra bizcity-twin-ai.php.',
				'fix_hint' => 'Thêm require_once __DIR__ . \'/core/helper/bootstrap.php\'; vào bizcity-twin-ai.php trước channel-gateway.',
				'steps'    => $steps,
			);
		}

		// ── Step 3: Runtime — make() ─────────────────────────────────────────
		$payload = BizCity_Error_Payload::make(
			'test_code',
			'Đây là message test.',
			'Đây là hint test.',
			'test_help_code',
			array( 'key' => 'val' )
		);

		$required_keys = array( 'success', 'code', 'message', 'hint', 'help_code' );
		$missing       = array();
		foreach ( $required_keys as $k ) {
			if ( ! array_key_exists( $k, $payload ) ) {
				$missing[] = $k;
			}
		}

		if ( empty( $missing ) && $payload['success'] === false && $payload['code'] === 'test_code' ) {
			$steps[] = array(
				'label'  => 'Runtime — make() trả đúng 5 trường (success/code/message/hint/help_code)',
				'status' => 'pass',
			);
		} else {
			$steps[] = array(
				'label'  => 'Runtime — make() trả đúng 5 trường',
				'status' => 'fail',
				'detail' => empty( $missing )
					? 'success !== false hoặc code sai'
					: 'Thiếu trường: ' . implode( ', ', $missing ),
			);
			return array(
				'status'  => 'fail',
				'summary' => 'make() không trả đúng contract.',
				'steps'   => $steps,
			);
		}

		// ── Step 4: Runtime — from_wp_error() ───────────────────────────────
		$wp_err = new WP_Error( 'token_invalid', 'Test WP_Error message.' );
		$p2     = BizCity_Error_Payload::from_wp_error( $wp_err, 'hint test', 'fb_token_expired' );

		if ( isset( $p2['code'] ) && $p2['code'] === 'token_invalid' && $p2['hint'] === 'hint test' ) {
			$steps[] = array(
				'label'  => 'Runtime — from_wp_error() map đúng code + hint',
				'status' => 'pass',
			);
		} else {
			$steps[] = array(
				'label'  => 'Runtime — from_wp_error() map đúng code + hint',
				'status' => 'fail',
				'detail' => 'code=' . ( $p2['code'] ?? 'N/A' ) . ' hint=' . ( $p2['hint'] ?? 'N/A' ),
			);
			return array(
				'status'  => 'fail',
				'summary' => 'from_wp_error() không map đúng code/hint.',
				'steps'   => $steps,
			);
		}

		// ── Step 5: Audit — legacy wp_send_json_error(string) scan ──────────
		$cg_file = plugin_dir_path( dirname( dirname( dirname( __FILE__ ) ) ) )
		           . 'core/channel-gateway/includes/class-admin-menu.php';

		if ( file_exists( $cg_file ) ) {
			$src     = file_get_contents( $cg_file ); // phpcs:ignore
			$matches = array();
			// [2026-06-05 Johnny Chu] R-ERROR-UX — detect raw string errors
			preg_match_all( '/wp_send_json_error\s*\(\s*[\'"][^\'"]+[\'"]\s*[,)]/m', $src, $matches );
			$count = count( $matches[0] );

			if ( $count === 0 ) {
				$steps[] = array(
					'label'  => 'Audit — class-admin-menu.php: 0 wp_send_json_error(string) legacy',
					'status' => 'pass',
				);
			} else {
				$steps[] = array(
					'label'  => "Audit — class-admin-menu.php: {$count} wp_send_json_error(string) cần migrate",
					'status' => 'warn',
					'detail' => 'Tìm thấy ' . $count . ' chỗ dùng wp_send_json_error(string). Cần migrate sang BizCity_Error_Payload::make() theo R-ERROR-UX.',
				);
			}
		} else {
			$steps[] = array(
				'label'  => 'Audit — class-admin-menu.php scan',
				'status' => 'skip',
				'detail' => 'File không tìm thấy — bỏ qua.',
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => 'BizCity_Error_Payload load OK, make() + from_wp_error() contract đúng.',
			'steps'   => $steps,
		);
	}

	// [2026-06-05 Johnny Chu] R-ERROR-UX — implement cleanup() from interface (no test artifacts).
	public function cleanup(): void {
		// Probe chỉ đọc, không tạo artifact → nothing to clean.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	// [2026-06-05 Johnny Chu] R-ERROR-UX — register probe
	$probes[] = 'BizCity_Probe_Error_UX';
	return $probes;
} );
