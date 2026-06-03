<?php
/**
 * BizCity Diagnostics — crm.audit-log probe.
 *
 * Verify 3-layer wiring (R-DDV) for M-CRM.M1.W3 Audit Log BE.
 *
 *   Layer 1 — DISK:
 *     - plugins/bizcity-twin-crm/includes/audit/class-audit-log.php
 *     - plugins/bizcity-twin-crm/includes/audit/class-audit-repository.php
 *     - frontend/src/redux/api/crmApi.js, frontend/src/routes/sales/SalesTab.jsx
 *   Layer 2 — LOADER:
 *     - classes BizCity_CRM_Audit_Log, BizCity_CRM_Audit_Repository loaded.
 *     - BIZCITY_CRM_DB_VERSION >= 1.17.0.
 *   Layer 3 — RUNTIME:
 *     - Table wp_bizcity_crm_audit_log exists with expected columns.
 *     - REST route /bizcity-crm/v1/audit registered.
 *     - Round-trip: Audit_Log::log_created() → find_by_entity() → cleanup.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      M-CRM.M1.W3 (2026-05-28)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_CRM_Audit_Log implements BizCity_Diagnostics_Probe {

	const DISK_FILES_PHP = array(
		'plugins/bizcity-twin-crm/includes/audit/class-audit-log.php',
		'plugins/bizcity-twin-crm/includes/audit/class-audit-repository.php',
		'plugins/bizcity-twin-crm/includes/class-rest-controller.php',
	);

	const DISK_FILES_OTHER = array(
		// Built artifact (production server does not ship frontend/src/).
		// Verify the dist bundle contains the audit RTK endpoint.
		'plugins/bizcity-twin-crm/assets/dist/inbox-app.js',
	);

	const REQUIRED_CLASSES = array(
		'BizCity_CRM_Audit_Log',
		'BizCity_CRM_Audit_Repository',
	);

	const EXPECTED_ROUTE   = '/bizcity-crm/v1/audit';
	const REQUIRED_DB_VER  = '1.17.0';
	const HEALTHTEST_TYPE  = '__healthtest_audit';

	const EXPECTED_COLUMNS = array(
		'id', 'entity_type', 'entity_id', 'action',
		'before_json', 'after_json',
		'user_id', 'user_label', 'event_uuid', 'created_at',
	);

	public function id(): string          { return 'crm.audit-log'; }
	public function label(): string       { return 'CRM · Audit Log (M-CRM.M1.W3)'; }
	public function description(): string {
		return 'Verify Audit Log BE: disk → loader → runtime (table, columns, REST /audit route, log_created/find_by_entity round-trip).';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 37; }
	public function icon(): string        { return 'list-view'; }
	public function estimate_ms(): int    { return 400; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
		$base       = $plugin_dir . '/bizcity-twin-ai/';
		$steps      = array();

		// ─── LAYER 1 · DISK ────────────────────────────────────────────────
		$all_files = array_merge( self::DISK_FILES_PHP, self::DISK_FILES_OTHER );
		foreach ( $all_files as $rel ) {
			$path   = $base . $rel;
			$exists = is_readable( $path );
			$size   = $exists ? filesize( $path ) : 0;
			$steps[] = $s = array(
				'label'  => 'Disk · ' . basename( $rel ),
				'status' => ( $exists && $size > 0 ) ? 'pass' : 'fail',
				'detail' => $exists ? "{$rel} · " . number_format( $size ) . ' bytes' : 'MISSING ' . $rel,
			);
			$ctx->emit_step( $s );
			if ( ! $exists ) {
				return array(
					'status'   => 'fail',
					'summary'  => 'File thiếu: ' . $rel,
					'error'    => 'file_missing',
					'fix_hint' => 'Deploy ' . $rel . ' (tạo file class-audit-log.php hoặc class-audit-repository.php nếu chưa có).',
					'steps'    => $steps,
				);
			}
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
					'fix_hint' => 'Re-save with create_file / replace_string_in_file (UTF-8 no BOM).',
					'steps'    => $steps,
				);
			}
		}

		// Verify built bundle contains the audit RTK endpoint signature.
		$dist_js = $base . 'plugins/bizcity-twin-crm/assets/dist/inbox-app.js';
		if ( is_readable( $dist_js ) ) {
			$bundle_has_audit = ( false !== strpos( (string) file_get_contents( $dist_js ), 'audit?entity_type=' ) );
			$steps[] = $s = array(
				'label'  => 'Disk · bundle contains getEntityAuditLog endpoint',
				'status' => $bundle_has_audit ? 'pass' : 'fail',
				'detail' => $bundle_has_audit
					? 'audit?entity_type= found in inbox-app.js'
					: 'NOT found — re-run npm build + upload inbox-app.js',
			);
			$ctx->emit_step( $s );
			if ( ! $bundle_has_audit ) {
				return array(
					'status'   => 'fail',
					'summary'  => 'Built bundle thiếu getEntityAuditLog endpoint.',
					'error'    => 'bundle_missing_endpoint',
					'fix_hint' => 'Chạy vite build trong frontend/ rồi upload assets/dist/inbox-app.js lên server.',
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
				'detail' => $ok ? 'loaded' : 'NOT loaded (require_once thiếu trong bootstrap.php? OPcache stale?)',
			);
			$ctx->emit_step( $s );
			if ( ! $ok ) {
				return array(
					'status'   => 'fail',
					'summary'  => 'Class ' . $cls . ' không load.',
					'error'    => 'class_missing',
					'fix_hint' => 'Kiểm tra bootstrap.php có require_once includes/audit/class-audit-*.php; reset OPcache nếu cần.',
					'steps'    => $steps,
				);
			}
		}

		// DB version constant must be >= 1.17.0.
		$ver_ok = defined( 'BIZCITY_CRM_DB_VERSION' )
			&& version_compare( BIZCITY_CRM_DB_VERSION, self::REQUIRED_DB_VER, '>=' );
		$steps[] = $s = array(
			'label'  => 'Loader · BIZCITY_CRM_DB_VERSION',
			'status' => $ver_ok ? 'pass' : 'fail',
			'detail' => $ver_ok
				? 'v' . BIZCITY_CRM_DB_VERSION . ' (>= ' . self::REQUIRED_DB_VER . ')'
				: 'expected >= ' . self::REQUIRED_DB_VER . ', got ' . ( defined( 'BIZCITY_CRM_DB_VERSION' ) ? BIZCITY_CRM_DB_VERSION : 'undefined' ),
		);
		$ctx->emit_step( $s );
		if ( ! $ver_ok ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'BIZCITY_CRM_DB_VERSION chưa bump lên ' . self::REQUIRED_DB_VER,
				'error'    => 'db_version_mismatch',
				'fix_hint' => 'Bump constant trong bizcity-twin-crm.php → ' . self::REQUIRED_DB_VER . ' và visit admin để trigger upgrade.',
				'steps'    => $steps,
			);
		}

		// ─── LAYER 3 · RUNTIME ────────────────────────────────────────────
		global $wpdb;

		if ( ! method_exists( 'BizCity_CRM_DB_Installer_V2', 'tbl_crm_audit_log' ) ) {
			$steps[] = $s = array(
				'label'  => 'Runtime · tbl_crm_audit_log helper',
				'status' => 'fail',
				'detail' => 'BizCity_CRM_DB_Installer_V2::tbl_crm_audit_log() chưa định nghĩa',
			);
			$ctx->emit_step( $s );
			return array(
				'status'   => 'fail',
				'summary'  => 'Helper tbl_crm_audit_log() thiếu trong DB installer.',
				'error'    => 'helper_missing',
				'steps'    => $steps,
			);
		}

		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_audit_log();
		$exists = ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
		$steps[] = $s = array(
			'label'  => 'Runtime · table bizcity_crm_audit_log',
			'status' => $exists ? 'pass' : 'fail',
			'detail' => $exists ? $tbl : $tbl . ' MISSING',
		);
		$ctx->emit_step( $s );
		if ( ! $exists ) {
			// AUTO-FIX: trigger idempotent migration.
			BizCity_CRM_DB_Installer_V2::migrate_phase_043();
			$exists = ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
			$steps[] = $s = array(
				'label'  => 'Runtime · auto-create via migrate_phase_043()',
				'status' => $exists ? 'pass' : 'fail',
				'detail' => $exists ? 'table created' : 'still MISSING after migrate: ' . $wpdb->last_error,
			);
			$ctx->emit_step( $s );
			if ( ! $exists ) {
				return array(
					'status'   => 'fail',
					'summary'  => 'Table ' . $tbl . ' không tạo được.',
					'error'    => 'table_create_failed',
					'fix_hint' => 'Chạy Site Provisioner hoặc inspect $wpdb->last_error.',
					'steps'    => $steps,
				);
			}
		}

		// Columns check.
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$tbl}`", 0 );
		$cols = is_array( $cols ) ? array_map( 'strtolower', $cols ) : array();
		$missing_cols = array_diff( self::EXPECTED_COLUMNS, $cols );
		$steps[] = $s = array(
			'label'  => 'Runtime · table columns',
			'status' => $missing_cols ? 'fail' : 'pass',
			'detail' => $missing_cols ? 'MISSING: ' . implode( ',', $missing_cols ) : count( $cols ) . ' cols OK',
		);
		$ctx->emit_step( $s );
		if ( $missing_cols ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Cột thiếu: ' . implode( ',', $missing_cols ),
				'error'    => 'columns_missing',
				'fix_hint' => 'Update changelog JSON + re-run BizCity_CRM_DB_Installer_V2::install().',
				'steps'    => $steps,
			);
		}

		// REST route registered?
		$server = rest_get_server();
		$routes = method_exists( $server, 'get_routes' ) ? array_keys( $server->get_routes() ) : array();
		$route_ok = in_array( self::EXPECTED_ROUTE, $routes, true );
		$steps[] = $s = array(
			'label'  => 'Runtime · REST ' . self::EXPECTED_ROUTE,
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok ? 'registered' : 'NOT registered',
		);
		$ctx->emit_step( $s );
		if ( ! $route_ok ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'REST route /audit chưa register.',
				'error'    => 'route_missing',
				'fix_hint' => 'Verify register_rest_route trong BizCity_CRM_REST_Controller::register_routes().',
				'steps'    => $steps,
			);
		}

		// Round-trip: log_created → find_by_entity → cleanup.
		$entity_id = random_int( 1000000, 9999999 ); // synthetic id space, won't collide with real CRM rows.
		$snapshot  = array( 'name' => 'probe row', 'amount' => 123 );
		$ins_id    = BizCity_CRM_Audit_Log::log_created( self::HEALTHTEST_TYPE, $entity_id, $snapshot );
		// log_created returns void; re-fetch via repository.
		$found = BizCity_CRM_Audit_Repository::find_by_entity( self::HEALTHTEST_TYPE, $entity_id, 10, 0 );
		$entries = is_array( $found['entries'] ?? null ) ? $found['entries'] : array();
		$first   = $entries[0] ?? null;

		$round_trip = ( $first
			&& (string) $first['entity_type'] === self::HEALTHTEST_TYPE
			&& (int) $first['entity_id']     === $entity_id
			&& (string) $first['action']     === 'created'
			&& is_array( $first['after'] )
			&& ( $first['after']['name'] ?? null ) === 'probe row' );

		$steps[] = $s = array(
			'label'  => 'Runtime · Audit_Log::log_created() → find_by_entity()',
			'status' => $round_trip ? 'pass' : 'fail',
			'detail' => $round_trip
				? 'round-trip OK · row id=' . ( $first['id'] ?? '?' ) . ' · total=' . (int) ( $found['total'] ?? 0 )
				: 'mismatch (entries=' . count( $entries ) . ', last_error=' . $wpdb->last_error . ')',
		);
		$ctx->emit_step( $s );

		// Cleanup synthetic row.
		$wpdb->delete( $tbl, array( 'entity_type' => self::HEALTHTEST_TYPE, 'entity_id' => $entity_id ) );

		if ( ! $round_trip ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Round-trip log/read fail.',
				'error'    => 'round_trip_failed',
				'steps'    => $steps,
			);
		}

		// Total real rows (entity_type NOT LIKE healthtest).
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$tbl}` WHERE entity_type NOT LIKE %s",
			'__healthtest_%'
		) );

		return array(
			'status'  => 'pass',
			'summary' => sprintf( 'OK — %d real audit rows · table + REST + round-trip green', $total ),
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		global $wpdb;
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' )
			|| ! method_exists( 'BizCity_CRM_DB_Installer_V2', 'tbl_crm_audit_log' ) ) {
			return;
		}
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_audit_log();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM `{$tbl}` WHERE entity_type LIKE %s",
			'__healthtest_%'
		) );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_CRM_Audit_Log';
	return $list;
} );
