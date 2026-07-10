<?php
/**
 * BizCity Diagnostics — modules.cf7-channel probe (PHASE-CG-CF7 2026-06-13)
 *
 * Validates the Contact Form 7 lead-capture channel pipeline:
 *   Layer 1 (Disk)    — 4 class files exist (installer, log, crm-sync, listener, rest).
 *   Layer 2 (Loader)  — All 5 classes loaded + listener hook attached.
 *   Layer 3 (Runtime) — bizcity_cf7_submissions table exists + all columns present,
 *                       REST GET /cf7/forms reachable, optional CRM sync available.
 *
 * No real CF7 form submission is triggered; safe to run on production.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-13 (PHASE-CG-CF7)
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

// [2026-06-13 Johnny Chu] PHASE-CG-CF7 — double-load guard.
if ( class_exists( 'BizCity_Probe_CF7_Channel', false ) ) {
	return;
}

final class BizCity_Probe_CF7_Channel implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'modules.cf7-channel'; }
	public function label(): string       { return 'Channel GW · Contact Form 7 Lead Capture (PHASE-CG-CF7)'; }
	public function description(): string {
		return 'Xác nhận CF7 channel pipeline: 5 class files trên disk, class loader + hook, bizcity_cf7_submissions schema, REST /cf7/forms, optional CRM sync.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 48; }
	public function icon(): string        { return 'form'; }
	public function estimate_ms(): int    { return 300; }

	public function precondition() {
		global $wpdb;
		$table = $wpdb->prefix . 'cf7_submissions'; // sanity — table presence checked in runtime layer
		return true; // no hard precondition; probe reports SKIP rows if CF7 not active
	}

	public function run( $ctx ): array {
		$steps = [];
		$plugin_dir = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/cf7/';

		/* ----------------------------------------------------------------
		 * Layer 1 — Disk
		 * ---------------------------------------------------------------- */
		$files = [
			'class-cf7-installer.php'          => 'BizCity_CF7_Installer',
			'class-cf7-submissions-log.php'    => 'BizCity_CF7_Submissions_Log',
			'class-cf7-crm-sync.php'           => 'BizCity_CF7_CRM_Sync',
			'class-cf7-channel-listener.php'   => 'BizCity_CF7_Channel_Listener',
			'class-cf7-rest.php'               => 'BizCity_CF7_REST',
		];

		$disk_ok = true;
		foreach ( $files as $filename => $classname ) {
			$path = $plugin_dir . $filename;
			if ( ! file_exists( $path ) ) {
				$steps[] = [
					'label'  => 'Disk · ' . $filename,
					'status' => 'FAIL',
					'detail' => 'File not found: ' . $path,
				];
				$disk_ok = false;
				continue;
			}
			// BOM check.
			$bytes = file_get_contents( $path, false, null, 0, 3 );
			if ( $bytes === "\xEF\xBB\xBF" ) {
				$steps[] = [
					'label'  => 'Disk · ' . $filename . ' (no BOM)',
					'status' => 'FAIL',
					'detail' => 'BOM detected — PHP output before <?php will break headers.',
				];
				$disk_ok = false;
			} else {
				$steps[] = [
					'label'  => 'Disk · ' . $filename,
					'status' => 'PASS',
					'detail' => 'OK · ' . number_format( filesize( $path ) ) . ' bytes.',
				];
			}
		}

		/* ----------------------------------------------------------------
		 * Layer 2 — Loader
		 * ---------------------------------------------------------------- */
		$loader_ok = true;
		foreach ( $files as $filename => $classname ) {
			$loaded = class_exists( $classname, false );
			$steps[] = [
				'label'  => 'Loader · ' . $classname,
				'status' => $loaded ? 'PASS' : 'FAIL',
				'detail' => $loaded
					? 'Class loaded.'
					: 'Class not in memory — check core/channel-gateway/bootstrap.php.',
			];
			if ( ! $loaded ) { $loader_ok = false; }
		}

		// Hook: wpcf7_mail_sent should have listener attached (only if CF7 active).
		$cf7_active = class_exists( 'WPCF7' ) || class_exists( 'WPCF7_ContactForm' );
		if ( $cf7_active ) {
			$hook_priority = has_action( 'wpcf7_mail_sent', array( 'BizCity_CF7_Channel_Listener', 'on_submit' ) );
			$steps[] = [
				'label'  => 'Loader · wpcf7_mail_sent hook',
				'status' => $hook_priority !== false ? 'PASS' : 'FAIL',
				'detail' => $hook_priority !== false
					? 'Hook attached at priority ' . $hook_priority . '.'
					: 'BizCity_CF7_Channel_Listener::on_submit not attached — plugins_loaded:20 callback did not fire or CF7 loaded after priority 20.',
			];
			if ( $hook_priority === false ) { $loader_ok = false; }
		} else {
			$steps[] = [
				'label'  => 'Loader · wpcf7_mail_sent hook',
				'status' => 'SKIP',
				'detail' => 'Contact Form 7 plugin không active — hook không đăng ký (bình thường).',
			];
		}

		// REST route registered.
		$rest_server   = rest_get_server();
		$rest_routes   = $rest_server->get_routes();
		$cf7_route_key = '/bizcity-channel/v1/cf7/forms';
		$rest_registered = isset( $rest_routes[ $cf7_route_key ] );
		$steps[] = [
			'label'  => 'Loader · REST ' . $cf7_route_key,
			'status' => $rest_registered ? 'PASS' : 'FAIL',
			'detail' => $rest_registered
				? 'Route registered in WP REST Server.'
				: 'Route not found — BizCity_CF7_REST::init() may not have been called.',
		];
		if ( ! $rest_registered ) { $loader_ok = false; }

		/* ----------------------------------------------------------------
		 * Layer 3 — Runtime
		 * ---------------------------------------------------------------- */
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_cf7_submissions';

		// Table existence.
		$table_exists = false;
		if ( class_exists( 'BizCity_CF7_Installer', false ) ) {
			$table_exists = BizCity_CF7_Installer::table_exists();
		} else {
			$result = bizcity_tbl_exists( $table ) ? $table : null; // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			$table_exists = ( $result === $table );
		}
		$steps[] = [
			'label'  => 'Runtime · table ' . $table,
			'status' => $table_exists ? 'PASS' : 'FAIL',
			'detail' => $table_exists
				? 'Table exists.'
				: 'Table missing — BizCity_CF7_Installer::maybe_install() not called or install() failed. Check PHP error log.',
		];

		// Required columns.
		if ( $table_exists ) {
			$required_cols = array( 'id', 'form_id', 'form_title', 'email', 'phone', 'raw_data', 'mapped_data', 'crm_action', 'crm_contact_id', 'crm_error', 'submitted_at', 'ip_address', 'user_agent' );
			$existing_cols = $wpdb->get_col( 'DESCRIBE `' . esc_sql( $table ) . '`' );
			$missing       = array_diff( $required_cols, $existing_cols );
			if ( empty( $missing ) ) {
				$steps[] = [
					'label'  => 'Runtime · bizcity_cf7_submissions columns',
					'status' => 'PASS',
					'detail' => count( $required_cols ) . ' required columns all present.',
				];
			} else {
				$steps[] = [
					'label'  => 'Runtime · bizcity_cf7_submissions columns',
					'status' => 'FAIL',
					'detail' => 'Missing columns: ' . implode( ', ', $missing ),
				];
			}
		}

		// Schema Registry.
		if ( class_exists( 'BizCity_Schema_Registry', false ) ) {
			$registered = BizCity_Schema_Registry::is_registered( 'bizcity_cf7_submissions' );
			$steps[] = [
				'label'  => 'Runtime · BizCity_Schema_Registry entry',
				'status' => $registered ? 'PASS' : 'FAIL',
				'detail' => $registered
					? 'bizcity_cf7_submissions registered in Schema Registry.'
					: 'bizcity_cf7_submissions not in Schema Registry — missing BizCity_Schema_Registry::register() in bootstrap.',
			];
		} else {
			$steps[] = [
				'label'  => 'Runtime · BizCity_Schema_Registry entry',
				'status' => 'SKIP',
				'detail' => 'BizCity_Schema_Registry class not loaded.',
			];
		}

		// CRM sync availability.
		$crm_available = class_exists( 'BizCity_CF7_CRM_Sync', false ) && BizCity_CF7_CRM_Sync::is_available();
		$steps[] = [
			'label'  => 'Runtime · CRM sync (BizCity_CF7_CRM_Sync)',
			'status' => $crm_available ? 'PASS' : 'SKIP',
			'detail' => $crm_available
				? 'CRM available — leads sẽ được đồng bộ vào bizcity_crm_contacts.'
				: 'CRM module chưa active hoặc bizcity_crm_contacts chưa tồn tại (bình thường nếu không dùng CRM).',
		];

		// Diagnostics changelog file exists.
		$changelog_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/diagnostics/changelog/modules.cf7-channel.json';
		$changelog_ok   = file_exists( $changelog_file );
		$steps[] = [
			'label'  => 'Runtime · R-DCL changelog file',
			'status' => $changelog_ok ? 'PASS' : 'FAIL',
			'detail' => $changelog_ok
				? 'core/diagnostics/changelog/modules.cf7-channel.json exists.'
				: 'Changelog missing — R-DCL violated. Create core/diagnostics/changelog/modules.cf7-channel.json.',
		];

		// Overall status.
		$has_fail = false;
		foreach ( $steps as $step ) {
			if ( isset( $step['status'] ) && $step['status'] === 'FAIL' ) {
				$has_fail = true;
				break;
			}
		}

		return [
			'status'  => $has_fail ? 'fail' : 'pass',
			'summary' => $has_fail
				? 'CF7 channel pipeline có vấn đề — xem các dòng FAIL bên dưới.'
				: 'CF7 channel pipeline OK · Disk + Loader + Schema PASS.',
			'steps'   => $steps,
		];
	}

	/**
	 * No artifacts created during run() — nothing to clean up.
	 */
	public function cleanup(): void {}
}

// Self-register.
add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_CF7_Channel';
	return $probes;
} );
