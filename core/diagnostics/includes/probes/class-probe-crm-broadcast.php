<?php
/**
 * BizCity Diagnostics — crm.broadcast probe (M-CRM.M4.Inbox · v1.18.0).
 *
 * Validates the Broadcast + Lead Classification sprint:
 *   Layer 1 (Disk)    — dist bundle exists, controller file sane (no BOM).
 *   Layer 2 (Loader)  — DB installer class loaded, version ≥ 1.18.0.
 *   Layer 3 (Runtime) — tables exist (auto-fix via migrate_phase_044),
 *                        lead_score column on contacts, REST routes registered.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-28 (M-CRM.M4.Inbox)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_CRM_Broadcast implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'crm.broadcast'; }
	public function label(): string       { return 'CRM · Broadcast + Lead Classification (M-CRM.M4.Inbox)'; }
	public function description(): string {
		return 'Kiểm tra bảng bizcity_crm_broadcasts, bizcity_crm_broadcast_recipients, cột lead_score/segment trên contacts, REST routes /broadcasts và /contacts/{id}/classify.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 38; }
	public function icon(): string        { return 'megaphone'; }
	public function estimate_ms(): int    { return 300; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) && ! class_exists( 'BizCity_CRM_DB_Installer' ) ) {
			return 'BizCity_CRM_DB_Installer chưa load — plugin bizcity-twin-crm chưa active hoặc chưa boot.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = [];

		/* ----------------------------------------------------------------
		 * Layer 1 — Disk
		 * ---------------------------------------------------------------- */

		// 1a. Dist bundle exists (FE optional — backend-only sites may skip Vite build).
		$dist = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-twin-crm/assets/dist/inbox-app.js';
		if ( file_exists( $dist ) ) {
			$steps[] = [
				'label'  => 'Disk · inbox-app.js exists',
				'status' => 'PASS',
				'detail' => 'Size: ' . number_format( filesize( $dist ) ) . ' bytes',
			];
		} else {
			$steps[] = [
				'label'  => 'Disk · inbox-app.js exists (FE bundle, optional)',
				'status' => 'SKIP',
				'detail' => 'FE bundle not deployed (' . $dist . '). Run `node node_modules/vite/bin/vite.js build` nếu cần UI Inbox.',
			];
		}

		// 1b. Controller file no BOM.
		$ctrl = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-twin-crm/includes/class-rest-controller.php';
		if ( file_exists( $ctrl ) ) {
			$bytes = file_get_contents( $ctrl, false, null, 0, 3 );
			if ( $bytes === "\xEF\xBB\xBF" ) {
				$steps[] = [
					'label'  => 'Disk · class-rest-controller.php no BOM',
					'status' => 'FAIL',
					'detail' => 'BOM detected — outputs whitespace before <?php, breaks REST routing.',
				];
			} else {
				$steps[] = [
					'label'  => 'Disk · class-rest-controller.php no BOM',
					'status' => 'PASS',
					'detail' => 'UTF-8 no BOM.',
				];
			}
		} else {
			$steps[] = [
				'label'  => 'Disk · class-rest-controller.php no BOM',
				'status' => 'SKIP',
				'detail' => 'File not found at expected path.',
			];
		}

		/* ----------------------------------------------------------------
		 * Layer 2 — Loader
		 * ---------------------------------------------------------------- */

		// 2a. Class loaded.
		$cls = class_exists( 'BizCity_CRM_DB_Installer_V2' ) ? 'BizCity_CRM_DB_Installer_V2'
			: ( class_exists( 'BizCity_CRM_DB_Installer' ) ? 'BizCity_CRM_DB_Installer' : null );
		if ( $cls ) {
			$steps[] = [
				'label'  => 'Loader · BizCity_CRM_DB_Installer loaded',
				'status' => 'PASS',
				'detail' => 'Class: ' . $cls,
			];
		} else {
			$steps[] = [
				'label'  => 'Loader · BizCity_CRM_DB_Installer loaded',
				'status' => 'FAIL',
				'detail' => 'Class not found in loaded files.',
			];
			// Cannot proceed to layer 3.
			return $steps;
		}

		// 2b. DB version ≥ 1.18.0.
		$ver = defined( 'BIZCITY_CRM_DB_VERSION' ) ? BIZCITY_CRM_DB_VERSION : '0.0.0';
		if ( version_compare( $ver, '1.18.0', '>=' ) ) {
			$steps[] = [
				'label'  => 'Loader · DB version ≥ 1.18.0',
				'status' => 'PASS',
				'detail' => 'BIZCITY_CRM_DB_VERSION = ' . $ver,
			];
		} else {
			$steps[] = [
				'label'  => 'Loader · DB version ≥ 1.18.0',
				'status' => 'FAIL',
				'detail' => 'Current: ' . $ver . '. Bump BIZCITY_CRM_DB_VERSION to ≥ 1.18.0 and run installer.',
			];
		}

		/* ----------------------------------------------------------------
		 * Layer 3 — Runtime
		 * ---------------------------------------------------------------- */
		global $wpdb;

		// Pre-flight auto-fix: if ANY of the v1.18.0 artifacts are missing
		// (table A, table B, lead_score column, segment column), run
		// migrate_phase_044() once. Handles partial-install state where
		// table exists but columns were never added (or vice versa).
		$tbl_bc       = $wpdb->prefix . 'bizcity_crm_broadcasts';
		$tbl_rcpt     = $wpdb->prefix . 'bizcity_crm_broadcast_recipients';
		$tbl_contacts = $wpdb->prefix . 'bizcity_crm_contacts';
		$need_migrate =
			( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_bc ) ) !== $tbl_bc )
			|| ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_rcpt ) ) !== $tbl_rcpt )
			|| ! $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$tbl_contacts}` LIKE %s", 'lead_score' ) )
			|| ! $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$tbl_contacts}` LIKE %s", 'segment' ) );
		if ( $need_migrate && method_exists( $cls, 'migrate_phase_044' ) ) {
			try {
				$cls::migrate_phase_044();
			} catch ( \Throwable $e ) {
				// noop — actual probe steps below will report what's still missing.
			}
		}

		// 3a. bizcity_crm_broadcasts table.
		$exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_bc ) ) === $tbl_bc;
		$steps[] = [
			'label'  => 'Runtime · table bizcity_crm_broadcasts',
			'status' => $exists ? 'PASS' : 'FAIL',
			'detail' => $exists ? 'Table exists.' : 'Missing even after migrate_phase_044() — check dbDelta error log.',
		];

		// 3b. bizcity_crm_broadcast_recipients table.
		$exists2  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_rcpt ) ) === $tbl_rcpt;
		$steps[]  = [
			'label'  => 'Runtime · table bizcity_crm_broadcast_recipients',
			'status' => $exists2 ? 'PASS' : 'FAIL',
			'detail' => $exists2 ? 'Table exists.' : 'Missing even after migrate_phase_044().',
		];

		// 3c. lead_score column on contacts.
		$col = $wpdb->get_row( $wpdb->prepare(
			'SHOW COLUMNS FROM `' . $tbl_contacts . '` LIKE %s',
			'lead_score'
		) );
		$steps[] = [
			'label'  => 'Runtime · contacts.lead_score column',
			'status' => $col ? 'PASS' : 'FAIL',
			'detail' => $col ? 'Column exists (type: ' . $col->Type . ').' : 'Missing — migrate_phase_044() ALTER may have failed (check `contacts` table has `points_balance_cache` column it expects to add AFTER).',
		];

		// 3d. segment column on contacts.
		$col2 = $wpdb->get_row( $wpdb->prepare(
			'SHOW COLUMNS FROM `' . $tbl_contacts . '` LIKE %s',
			'segment'
		) );
		$steps[] = [
			'label'  => 'Runtime · contacts.segment column',
			'status' => $col2 ? 'PASS' : 'FAIL',
			'detail' => $col2 ? 'Column exists (type: ' . $col2->Type . ').' : 'Missing — migrate_phase_044() ALTER may have failed.',
		];

		// 3e. REST route /bizcity-crm/v1/broadcasts registered.
		$routes    = rest_get_server()->get_routes();
		$bc_route  = '/bizcity-crm/v1/broadcasts';
		$clf_route = '/bizcity-crm/v1/contacts/(?P<id>[\d]+)/classify';
		$has_bc    = isset( $routes[ $bc_route ] );
		$steps[]   = [
			'label'  => 'Runtime · REST /bizcity-crm/v1/broadcasts registered',
			'status' => $has_bc ? 'PASS' : 'FAIL',
			'detail' => $has_bc ? 'Route found.' : 'Route missing — BizCity_CRM_REST_Controller may not have run register_routes().',
		];

		// 3f. classify REST route.
		$has_clf = false;
		foreach ( array_keys( $routes ) as $pattern ) {
			if ( strpos( $pattern, '/bizcity-crm/v1/contacts' ) !== false && strpos( $pattern, 'classify' ) !== false ) {
				$has_clf = true;
				break;
			}
		}
		$steps[] = [
			'label'  => 'Runtime · REST /bizcity-crm/v1/contacts/{id}/classify registered',
			'status' => $has_clf ? 'PASS' : 'FAIL',
			'detail' => $has_clf ? 'Route found.' : 'Route missing.',
		];

		return $steps;
	}

	public function cleanup(): void {} // no persistent artifacts to clean up
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = new BizCity_Probe_CRM_Broadcast();
	return $list;
} );
