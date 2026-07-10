<?php
/**
 * BizCity Diagnostics — crm.sales-pipeline probe.
 *
 * Verify 3-layer wiring (R-DDV) for M-CRM.M5 Sales Pipeline FE+BE.
 *
 *   Layer 1 — DISK:
 *     - plugins/bizcity-twin-crm/{bizcity-twin-crm.php,includes/class-rest-controller.php,
 *       includes/class-db-installer.php,frontend/src/routes/sales/SalesTab.jsx,
 *       frontend/src/redux/api/crmApi.js} exist + readable + no BOM (PHP only).
 *   Layer 2 — LOADER:
 *     - classes BizCity_CRM_REST_Controller, BizCity_CRM_DB_Installer loaded.
 *     - constant BIZCITY_CRM_REST_NS === 'bizcity-crm/v1'.
 *   Layer 3 — RUNTIME:
 *     - Tables wp_bizcity_crm_{leads,opportunities,contracts} exist.
 *     - REST routes /bizcity-crm/v1/crm-{leads,opportunities,contracts} registered.
 *     - INSERT/SELECT/UPDATE(stage)/DELETE round-trip on opportunities table
 *       with `__healthtest_` marker → simulates Kanban drag.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      M-CRM.M5 (2026-05-25)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_CRM_Pipeline', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Pipeline implements BizCity_Diagnostics_Probe {

	const DISK_FILES_PHP = array(
		'plugins/bizcity-twin-crm/bizcity-twin-crm.php',
		'plugins/bizcity-twin-crm/includes/class-rest-controller.php',
		'plugins/bizcity-twin-crm/includes/class-db-installer.php',
	);

	// FE source files (dev-time only, may not be on prod servers — informational, never gating).
	const DISK_FILES_OTHER = array(
		'plugins/bizcity-twin-crm/frontend/src/routes/sales/SalesTab.jsx',
		'plugins/bizcity-twin-crm/frontend/src/redux/api/crmApi.js',
	);

	const REQUIRED_CLASSES = array(
		'BizCity_CRM_REST_Controller',
		'BizCity_CRM_DB_Installer',
	);

	const EXPECTED_NS = 'bizcity-crm/v1';

	const EXPECTED_ROUTES = array(
		'/bizcity-crm/v1/crm-leads',
		'/bizcity-crm/v1/crm-opportunities',
		'/bizcity-crm/v1/crm-contracts',
	);

	public function id(): string          { return 'crm.sales-pipeline'; }
	public function label(): string       { return 'CRM · Sales Pipeline (M-CRM.M5)'; }
	public function description(): string {
		return 'Verify Sales Pipeline (Lead/Opportunity/Contract): disk → loader → runtime (tables, REST routes, INSERT/UPDATE/DELETE round-trip simulating Kanban drag).';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 36; }
	public function icon(): string        { return 'briefcase'; }
	public function estimate_ms(): int    { return 600; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
		$base       = $plugin_dir . '/bizcity-twin-ai/';
		$steps      = array();

		// ─── LAYER 1 · DISK ────────────────────────────────────────────────
		// Only PHP files are required runtime artifacts — gate hard-fail on those.
		foreach ( self::DISK_FILES_PHP as $rel ) {
			$path   = $base . $rel;
			$exists = is_readable( $path );
			$size   = $exists ? filesize( $path ) : 0;
			$ctx->emit_step( $s = array(
				'label'  => 'Disk · ' . basename( $rel ),
				'status' => ( $exists && $size > 0 ) ? 'pass' : 'fail',
				'detail' => $exists ? "{$rel} · " . number_format( $size ) . ' bytes' : 'MISSING ' . $rel,
			) );
			$steps[] = $s;
			if ( ! $exists ) {
				return array(
					'status'   => 'fail',
					'summary'  => 'PHP file thiếu: ' . $rel,
					'error'    => 'file_missing',
					'fix_hint' => 'Deploy ' . $rel . ' lên server.',
					'steps'    => $steps,
				);
			}
		}

		// FE source files: informational only — server may not host /frontend/src (dev-only).
		foreach ( self::DISK_FILES_OTHER as $rel ) {
			$path   = $base . $rel;
			$exists = is_readable( $path );
			$size   = $exists ? filesize( $path ) : 0;
			$ctx->emit_step( $s = array(
				'label'  => 'Disk · ' . basename( $rel ) . ' (FE source, optional)',
				'status' => $exists && $size > 0 ? 'pass' : 'skip',
				'detail' => $exists ? "{$rel} · " . number_format( $size ) . ' bytes' : 'Not on server (dev-only source — OK to skip).',
			) );
			$steps[] = $s;
		}

		// BOM trap (PHP only).
		foreach ( self::DISK_FILES_PHP as $rel ) {
			$path = $base . $rel;
			$head = file_get_contents( $path, false, null, 0, 3 );
			$has_bom = ( $head !== false && strlen( $head ) === 3
				&& ord( $head[0] ) === 0xEF && ord( $head[1] ) === 0xBB && ord( $head[2] ) === 0xBF );
			if ( $has_bom ) {
				$steps[] = $s = array( 'label' => 'Disk · BOM', 'status' => 'fail', 'detail' => 'BOM in ' . basename( $rel ) );
				$ctx->emit_step( $s );
				return array(
					'status'   => 'fail',
					'summary'  => 'BOM detected in ' . $rel,
					'error'    => 'bom_present',
					'fix_hint' => 'Re-save with create_file/replace_string_in_file (UTF-8 no BOM).',
					'steps'    => $steps,
				);
			}
		}

		// ─── LAYER 2 · LOADER ─────────────────────────────────────────────
		foreach ( self::REQUIRED_CLASSES as $cls ) {
			$ok = class_exists( $cls );
			$steps[] = $s = array(
				'label'  => 'Loader · class ' . $cls,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $ok ? 'loaded' : 'NOT loaded (plugin inactive? OPcache stale?)',
			);
			$ctx->emit_step( $s );
			if ( ! $ok ) {
				return array(
					'status'   => 'fail',
					'summary'  => 'Class ' . $cls . ' không load.',
					'error'    => 'class_missing',
					'fix_hint' => 'Activate plugin bizcity-twin-crm hoặc reset OPcache.',
					'steps'    => $steps,
				);
			}
		}

		$ns_ok = ( defined( 'BIZCITY_CRM_REST_NS' ) && BIZCITY_CRM_REST_NS === self::EXPECTED_NS );
		$steps[] = $s = array(
			'label'  => 'Loader · const BIZCITY_CRM_REST_NS',
			'status' => $ns_ok ? 'pass' : 'fail',
			'detail' => $ns_ok ? self::EXPECTED_NS : 'unexpected value (' . ( defined( 'BIZCITY_CRM_REST_NS' ) ? BIZCITY_CRM_REST_NS : 'undefined' ) . ')',
		);
		$ctx->emit_step( $s );
		if ( ! $ns_ok ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'REST namespace constant mismatch.',
				'error'    => 'ns_mismatch',
				'steps'    => $steps,
			);
		}

		// ─── LAYER 3 · RUNTIME ────────────────────────────────────────────
		global $wpdb;

		$tbl_leads     = BizCity_CRM_DB_Installer::tbl_crm_leads();
		$tbl_opps      = BizCity_CRM_DB_Installer::tbl_crm_opportunities();
		$tbl_contracts = BizCity_CRM_DB_Installer::tbl_crm_contracts();

		foreach ( array(
			'leads'         => $tbl_leads,
			'opportunities' => $tbl_opps,
			'contracts'     => $tbl_contracts,
		) as $label => $tbl ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$exists = ( bizcity_tbl_exists( $tbl ) ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			$steps[] = $s = array(
				'label'  => 'Runtime · table ' . $label,
				'status' => $exists ? 'pass' : 'fail',
				'detail' => $exists ? $tbl : $tbl . ' MISSING',
			);
			$ctx->emit_step( $s );
			if ( ! $exists ) {
				return array(
					'status'   => 'fail',
					'summary'  => 'Table ' . $tbl . ' chưa tồn tại.',
					'error'    => 'table_missing',
					'fix_hint' => 'Run BizCity_CRM_DB_Installer::install() hoặc visit Site Provisioner.',
					'steps'    => $steps,
				);
			}
		}

		// REST routes registered?
		$server = rest_get_server();
		$routes = method_exists( $server, 'get_routes' ) ? array_keys( $server->get_routes() ) : array();
		foreach ( self::EXPECTED_ROUTES as $route ) {
			$ok = in_array( $route, $routes, true );
			$steps[] = $s = array(
				'label'  => 'Runtime · REST ' . basename( $route ),
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $ok ? $route : $route . ' NOT registered',
			);
			$ctx->emit_step( $s );
			if ( ! $ok ) {
				return array(
					'status'   => 'fail',
					'summary'  => 'REST route ' . $route . ' không đăng ký.',
					'error'    => 'route_missing',
					'fix_hint' => 'Verify BizCity_CRM_REST_Controller::register_routes() hook.',
					'steps'    => $steps,
				);
			}
		}

		// INSERT → UPDATE(stage) → DELETE round-trip on opportunities (simulates Kanban drag).
		$marker = '__healthtest_' . wp_generate_password( 8, false, false );
		$now    = current_time( 'mysql' );
		$ins = $wpdb->insert( $tbl_opps, array(
			'name'             => $marker,
			'stage'            => 'qualification',
			'status'           => 'open',
			'amount'           => 0,
			'currency'         => 'VND',
			'probability'      => 10,
			'expected_revenue' => 0,
			'custom_json'      => '{}',
			'created_at'       => $now,
			'updated_at'       => $now,
		) );
		$insert_id = $ins ? (int) $wpdb->insert_id : 0;
		$round_trip = false;
		$stage_changed = false;
		if ( $insert_id ) {
			$upd = $wpdb->update(
				$tbl_opps,
				array( 'stage' => 'proposal', 'updated_at' => $now ),
				array( 'id' => $insert_id )
			);
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, stage FROM {$tbl_opps} WHERE id=%d", $insert_id ) );
			$stage_changed = ( $row && (string) $row->stage === 'proposal' );
			$round_trip = ( $upd !== false && $stage_changed );
			$wpdb->delete( $tbl_opps, array( 'id' => $insert_id ) );
		}
		$steps[] = $s = array(
			'label'  => 'Runtime · INSERT/UPDATE/DELETE (Kanban drag sim)',
			'status' => $round_trip ? 'pass' : 'fail',
			'detail' => $round_trip ? 'round-trip id=' . $insert_id . ' stage=qualification→proposal' : 'failed: ' . $wpdb->last_error,
		);
		$ctx->emit_step( $s );

		if ( ! $round_trip ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Runtime smoke fail (Kanban drag simulation).',
				'error'    => 'round_trip_failed',
				'steps'    => $steps,
			);
		}

		$lead_count     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_leads}" );
		$opp_count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_opps}" );
		$contract_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_contracts}" );

		return array(
			'status'  => 'pass',
			'summary' => sprintf( 'OK — %d leads · %d opps · %d contracts', $lead_count, $opp_count, $contract_count ),
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		global $wpdb;
		if ( ! class_exists( 'BizCity_CRM_DB_Installer' ) ) { return; }
		$tbl = BizCity_CRM_DB_Installer::tbl_crm_opportunities();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$tbl} WHERE name LIKE %s",
			'__healthtest_%'
		) );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_CRM_Pipeline';
	return $list;
} );
