<?php
/**
 * BizCity Diagnostics - Membership expiry cohorts probe (Sprint 7).
 *
 * R-DDV evidence:
 * - Disk: expiry cohort/report/admin/cron markers exist in membership files.
 * - Loader: report class + method are loaded.
 * - Runtime: expiry_cohorts() returns expected numeric keys and monotonic counts.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-17
 */

// [2026-07-17 Johnny Chu] SPRINT-7 MBR-EXPIRY - DDV probe for expiry cohort dashboard + cron evidence.
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	$_iface_path = defined( 'BIZCITY_DIAGNOSTICS_DIR' )
		? BIZCITY_DIAGNOSTICS_DIR . 'includes/interface-diagnostics-probe.php'
		: dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
	if ( is_readable( $_iface_path ) ) {
		require_once $_iface_path;
	}
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_Membership_Expiry_Cohorts', false ) ) {
	return;
}

final class BizCity_Probe_Membership_Expiry_Cohorts implements BizCity_Diagnostics_Probe {

	const HEALTH_SOURCE = '__healthtest_expiry_cohorts';

	public function id(): string { return 'core.membership.expiry_cohorts'; }
	public function label(): string { return 'Membership - Expiry Cohorts (Sprint 7)'; }
	public function description(): string {
		return 'Verifies expiry cohort aggregation, Members tab expiry visibility, and cron counters for 7d/30d evidence.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 63; }
	public function icon(): string { return 'Calendar'; }
	public function estimate_ms(): int { return 80; }

	public function precondition() {
		// [2026-07-17 Johnny Chu] PROBE-RECHECK HOTFIX — diagnostics may run under REST context where admin-only files are not auto-loaded.
		$report_file = $this->resolve_plugin_file( 'core/membership/includes/admin/class-membership-revenue-report.php' );
		if ( ! class_exists( 'BizCity_Membership_Revenue_Report' ) && $report_file !== '' ) {
			if ( is_readable( $report_file ) ) {
				require_once $report_file;
			}
		}
		if ( ! class_exists( 'BizCity_Membership_Revenue_Report' ) ) {
			return new WP_Error( 'no_report_class', 'BizCity_Membership_Revenue_Report is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_Membership_Cron' ) ) {
			return new WP_Error( 'no_cron_class', 'BizCity_Membership_Cron is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		$report_file = $this->resolve_plugin_file( 'core/membership/includes/admin/class-membership-revenue-report.php' );
		$admin_file  = $this->resolve_plugin_file( 'core/membership/includes/admin/class-membership-admin-page.php' );
		$cron_file   = $this->resolve_plugin_file( 'core/membership/includes/class-membership-cron.php' );

		$report_src = ( $report_file !== '' && is_readable( $report_file ) ) ? (string) file_get_contents( $report_file ) : '';
		$admin_src  = ( $admin_file !== '' && is_readable( $admin_file ) ) ? (string) file_get_contents( $admin_file ) : '';
		$cron_src   = ( $cron_file !== '' && is_readable( $cron_file ) ) ? (string) file_get_contents( $cron_file ) : '';

		$disk_report_ok = $report_src !== ''
			&& strpos( $report_src, 'function expiry_cohorts' ) !== false
			&& strpos( $report_src, "'7d'" ) !== false
			&& strpos( $report_src, "'30d'" ) !== false;
		$step = array(
			'label'  => 'Disk - revenue report contains expiry_cohorts() with 7d/30d keys',
			'status' => $disk_report_ok ? 'pass' : 'fail',
			'detail' => $disk_report_ok ? 'markers found in class-membership-revenue-report.php' : 'missing method or key markers in report file',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_report_ok ) {
			$pass = false;
		}

		$disk_admin_ok = $admin_src !== ''
			&& strpos( $admin_src, 'load_active_expiry_map' ) !== false
			&& strpos( $admin_src, 'Days left' ) !== false
			&& strpos( $admin_src, 'Expiration' ) !== false;
		$step = array(
			'label'  => 'Disk - Members tab has Expiration/Days left columns',
			'status' => $disk_admin_ok ? 'pass' : 'fail',
			'detail' => $disk_admin_ok ? 'column + helper markers found in class-membership-admin-page.php' : 'members tab column markers are missing',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_admin_ok ) {
			$pass = false;
		}

		$disk_cron_ok = $cron_src !== ''
			&& strpos( $cron_src, "'expiry_7d_count'" ) !== false
			&& strpos( $cron_src, "'expiry_30d_count'" ) !== false;
		$step = array(
			'label'  => 'Disk - membership cron emits expiry_7d_count and expiry_30d_count',
			'status' => $disk_cron_ok ? 'pass' : 'fail',
			'detail' => $disk_cron_ok ? 'counter markers found in class-membership-cron.php' : 'cron counter markers missing',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_cron_ok ) {
			$pass = false;
		}

		$loader_class_ok = class_exists( 'BizCity_Membership_Revenue_Report' );
		$loader_ok = $loader_class_ok && method_exists( 'BizCity_Membership_Revenue_Report', 'expiry_cohorts' );
		$loader_file = $report_file !== '' ? $report_file : 'unresolved';
		if ( $loader_class_ok ) {
			try {
				$ref = new ReflectionClass( 'BizCity_Membership_Revenue_Report' );
				$rf  = (string) $ref->getFileName();
				if ( $rf !== '' ) {
					$loader_file = $rf;
				}
			} catch ( Exception $e ) {
				// Keep fallback file path above.
			}
		}
		$loader_diag = sprintf(
			'class=%s;method=%s;file=%s',
			$loader_class_ok ? '1' : '0',
			$loader_ok ? '1' : '0',
			basename( (string) $loader_file )
		);
		$step = array(
			'label'  => 'Loader - BizCity_Membership_Revenue_Report::expiry_cohorts() loaded',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'method loaded; ' . $loader_diag : 'method missing at runtime; ' . $loader_diag,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $loader_ok ) {
			$pass = false;
			return array(
				'status'   => 'fail',
				'summary'  => 'Membership expiry cohorts loader failed — method missing at runtime.',
				'error'    => 'membership_expiry_cohorts_loader_missing',
				'fix_hint' => 'Ensure updated class-membership-revenue-report.php with expiry_cohorts() is deployed and loaded before rerunning probe. Runtime=' . $loader_diag,
				'steps'    => $steps,
			);
		}

		$cohorts = BizCity_Membership_Revenue_Report::instance()->expiry_cohorts();
		$required_keys = array( '7d', '14d', '30d', '60d', '90d' );
		$shape_ok = is_array( $cohorts );
		$typed_ok = true;
		foreach ( $required_keys as $k ) {
			if ( ! array_key_exists( $k, $cohorts ) ) {
				$shape_ok = false;
				$typed_ok = false;
				continue;
			}
			if ( ! is_int( $cohorts[ $k ] ) || $cohorts[ $k ] < 0 ) {
				$typed_ok = false;
			}
		}

		$step = array(
			'label'  => 'Runtime - expiry_cohorts() shape and non-negative integer values',
			'status' => ( $shape_ok && $typed_ok ) ? 'pass' : 'fail',
			'detail' => ( $shape_ok && $typed_ok )
				? sprintf(
					'7d=%d, 14d=%d, 30d=%d, 60d=%d, 90d=%d',
					(int) $cohorts['7d'],
					(int) $cohorts['14d'],
					(int) $cohorts['30d'],
					(int) $cohorts['60d'],
					(int) $cohorts['90d']
				)
				: 'missing key(s) or invalid cohort value type',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! ( $shape_ok && $typed_ok ) ) {
			$pass = false;
		}

		$monotonic_ok = $shape_ok
			&& (int) $cohorts['7d'] <= (int) $cohorts['14d']
			&& (int) $cohorts['14d'] <= (int) $cohorts['30d']
			&& (int) $cohorts['30d'] <= (int) $cohorts['60d']
			&& (int) $cohorts['60d'] <= (int) $cohorts['90d'];
		$step = array(
			'label'  => 'Runtime - cohort counts are cumulative (7d <= 14d <= 30d <= 60d <= 90d)',
			'status' => $monotonic_ok ? 'pass' : 'fail',
			'detail' => $monotonic_ok ? 'ordering is valid' : 'cohort ordering is inconsistent',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $monotonic_ok ) {
			$pass = false;
		}

		// [2026-07-17 Johnny Chu] SPRINT-7 MBR-EXPIRY — synthetic runtime evidence with cleanup.
		$sub_table = $this->subscriptions_table();
		if ( ! $this->table_exists( $sub_table ) ) {
			$step = array(
				'label'  => 'Runtime - synthetic cohort insertion and delta validation',
				'status' => 'skip',
				'detail' => 'Subscriptions table missing, skip synthetic runtime test.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		} else {
			$this->cleanup_health_rows( $sub_table );
			delete_transient( 'bizm_membership_expiry_cohorts' );

			$before = BizCity_Membership_Revenue_Report::instance()->expiry_cohorts();
			$now    = (int) current_time( 'timestamp' );

			$future_3d  = gmdate( 'Y-m-d H:i:s', strtotime( '+3 days', $now ) );
			$future_45d = gmdate( 'Y-m-d H:i:s', strtotime( '+45 days', $now ) );
			$past_1d    = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day', $now ) );

			$insert_ok = true;
			$insert_ok = $this->insert_health_row( $sub_table, 990007001, $future_3d ) && $insert_ok;
			$insert_ok = $this->insert_health_row( $sub_table, 990007002, $future_45d ) && $insert_ok;
			$insert_ok = $this->insert_health_row( $sub_table, 990007003, $past_1d ) && $insert_ok;

			delete_transient( 'bizm_membership_expiry_cohorts' );
			$after = BizCity_Membership_Revenue_Report::instance()->expiry_cohorts();

			$delta = array(
				'7d'  => (int) ( ( $after['7d'] ?? 0 ) - ( $before['7d'] ?? 0 ) ),
				'14d' => (int) ( ( $after['14d'] ?? 0 ) - ( $before['14d'] ?? 0 ) ),
				'30d' => (int) ( ( $after['30d'] ?? 0 ) - ( $before['30d'] ?? 0 ) ),
				'60d' => (int) ( ( $after['60d'] ?? 0 ) - ( $before['60d'] ?? 0 ) ),
				'90d' => (int) ( ( $after['90d'] ?? 0 ) - ( $before['90d'] ?? 0 ) ),
			);

			$synthetic_ok = $insert_ok
				&& $delta['7d'] >= 1
				&& $delta['14d'] >= 1
				&& $delta['30d'] >= 1
				&& $delta['60d'] >= 2
				&& $delta['90d'] >= 2;

			$step = array(
				'label'  => 'Runtime - synthetic cohort insertion and delta validation',
				'status' => $synthetic_ok ? 'pass' : 'fail',
				'detail' => $synthetic_ok
					? sprintf( 'delta 7/14/30/60/90 = %d/%d/%d/%d/%d', $delta['7d'], $delta['14d'], $delta['30d'], $delta['60d'], $delta['90d'] )
					: ( $insert_ok
						? sprintf( 'unexpected delta 7/14/30/60/90 = %d/%d/%d/%d/%d', $delta['7d'], $delta['14d'], $delta['30d'], $delta['60d'], $delta['90d'] )
						: 'failed inserting synthetic rows'
					),
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $synthetic_ok ) {
				$pass = false;
			}

			$this->cleanup_health_rows( $sub_table );
			delete_transient( 'bizm_membership_expiry_cohorts' );
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'Membership expiry cohorts are wired across report, admin, and cron evidence.'
				: 'Membership expiry cohorts contract failed in one or more Disk/Loader/Runtime checks.',
			'error'    => $pass ? '' : 'membership_expiry_cohorts_contract_failed',
			'fix_hint' => $pass ? '' : 'Check membership report/admin/cron Sprint-7 markers and method wiring.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		$table = $this->subscriptions_table();
		if ( $this->table_exists( $table ) ) {
			$this->cleanup_health_rows( $table );
		}
		delete_transient( 'bizm_membership_expiry_cohorts' );
	}

	/**
	 * Resolve subscriptions table name.
	 *
	 * @return string
	 */
	private function subscriptions_table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_member_subscriptions';
	}

	/**
	 * Resolve a plugin-relative file path with fallback candidates.
	 *
	 * @param string $relative_path
	 * @return string
	 */
	private function resolve_plugin_file( $relative_path ) {
		$relative_path = ltrim( (string) $relative_path, '/\\' );
		$candidates = array();
		if ( defined( 'BIZCITY_TWIN_AI_DIR' ) ) {
			$candidates[] = BIZCITY_TWIN_AI_DIR . $relative_path;
		}
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$candidates[] = WP_PLUGIN_DIR . '/bizcity-twin-ai/' . $relative_path;
		}
		// [2026-07-17 Johnny Chu] PROBE-RECHECK HOTFIX — correct fallback depth to plugin root.
		$candidates[] = dirname( __DIR__, 4 ) . '/' . $relative_path;
		$candidates[] = dirname( __DIR__, 5 ) . '/bizcity-twin-ai/' . $relative_path;

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && $candidate !== '' && is_readable( $candidate ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Check physical table existence without SHOW TABLES.
	 *
	 * @param string $table
	 * @return bool
	 */
	private function table_exists( $table ) {
		global $wpdb;
		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			return (bool) bizcity_tbl_exists( $table );
		}
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table
			)
		);
	}

	/**
	 * Remove synthetic rows created by this probe.
	 *
	 * @param string $table
	 * @return void
	 */
	private function cleanup_health_rows( $table ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE source = %s",
				self::HEALTH_SOURCE
			)
		);
	}

	/**
	 * Insert one synthetic subscription row for runtime validation.
	 *
	 * @param string $table
	 * @param int    $user_id
	 * @param string $expiration
	 * @return bool
	 */
	private function insert_health_row( $table, $user_id, $expiration ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$res = $wpdb->insert(
			$table,
			array(
				'user_id'                => (int) $user_id,
				'plan_slug'              => 'pro',
				'status'                 => BizCity_Membership_Manager::STATUS_ACTIVE,
				'start_date'             => $now,
				'expiration_date'        => (string) $expiration,
				'paypal_subscription_id' => '',
				'source'                 => self::HEALTH_SOURCE,
				'created_at'             => $now,
				'updated_at'             => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return false !== $res;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Membership_Expiry_Cohorts';
	return $list;
} );
