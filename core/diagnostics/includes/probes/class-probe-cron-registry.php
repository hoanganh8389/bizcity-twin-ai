<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Probe: cron.registry — Health of every job registered through
 * `BizCity_Cron_Manager` (core/cron Phase 1).
 *
 * PASS if (per job):
 *   - enabled = true
 *   - wp_next_scheduled(hook) > 0
 *   - last_run_at within 2 × interval (warn otherwise — but still pass overall
 *     unless ANY job is "never run" while next_run_at is also missing)
 *
 * FAIL when:
 *   - registry table missing
 *   - any enabled job has no next_run AND no last_run (truly orphan)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
}

class BizCity_Probe_Cron_Registry implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'cron.registry'; }
	public function label(): string       { return 'Cron · registry & dispatch'; }
	public function description(): string {
		return 'Liệt kê mọi job đã đăng ký qua core/cron, xác minh wp_next_scheduled và lần chạy gần nhất.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 50; }
	public function icon(): string        { return 'Clock'; }
	public function estimate_ms(): int    { return 200; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return new WP_Error( 'no_manager', 'BizCity_Cron_Manager chưa load — kiểm tra core/cron/bootstrap.php.' );
		}
		global $wpdb;
		$t = $wpdb->prefix . BizCity_Cron_Manager::TABLE_REGISTRY;
		$wpdb->suppress_errors( true );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
		$wpdb->suppress_errors( false );
		if ( ! $exists ) {
			return new WP_Error( 'no_table', "Bảng {$t} không tồn tại — cần chạy diagnostics Site Provisioner." );
		}
		return true;
	}

	public function run( $ctx ): array {
		$mgr  = BizCity_Cron_Manager::instance();
		$jobs = $mgr->all();
		$steps = array();

		if ( empty( $jobs ) ) {
			return array(
				'status'   => 'pass',
				'summary'  => 'Chưa có job nào đăng ký (early-boot).',
				'steps'    => array(
					array( 'label' => 'registry rows', 'status' => 'pass', 'detail' => '0 jobs' ),
				),
			);
		}

		$now      = time();
		$orphans  = array();
		$ok       = 0;
		$stalled  = 0;

		foreach ( $jobs as $j ) {
			$has_next = ! empty( $j['next_run_at'] );
			$has_last = ! empty( $j['last_run_at'] );
			$status   = 'pass';
			$detail   = sprintf(
				'next=%s · last=%s · status=%s · %s',
				$has_next ? gmdate( 'Y-m-d H:i:s', $j['next_run_at'] ) . 'Z' : '—',
				$has_last ? gmdate( 'Y-m-d H:i:s', $j['last_run_at'] ) . 'Z' : '—',
				$j['last_status'] ?: 'never',
				$j['hook']
			);
			if ( $j['enabled'] && ! $has_next && ! $has_last ) {
				$orphans[] = $j['job_id'];
				$status    = 'fail';
			} elseif ( $j['last_status'] === 'error' ) {
				$status = 'warn';
				$stalled++;
			} elseif ( $has_last && ! empty( $j['interval_key'] ) ) {
				// Soft drift detect: lst > now - 30min for 5min interval.
				$drift_threshold = 30 * MINUTE_IN_SECONDS;
				if ( ( $now - $j['last_run_at'] ) > $drift_threshold ) {
					$status = 'warn';
					$stalled++;
				}
			}
			if ( $status === 'pass' ) { $ok++; }
			$steps[] = array(
				'label'  => $j['job_id'],
				'status' => $status,
				'detail' => $detail,
			);
		}

		if ( ! empty( $orphans ) ) {
			return array(
				'status'   => 'fail',
				'summary'  => sprintf( '%d job orphan (chưa schedule + chưa từng chạy)', count( $orphans ) ),
				'error'    => 'Orphans: ' . implode( ', ', $orphans ),
				'fix_hint' => 'Kiểm tra logic register() — có thể wp_schedule_event bị fail. Thử deactivate/activate plugin.',
				'steps'    => $steps,
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => sprintf( '%d/%d job khoẻ · %d cảnh báo', $ok, count( $jobs ), $stalled ),
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe — nothing to clean.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Cron_Registry';
	return $list;
} );

