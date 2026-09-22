<?php
/**
 * BizCity Diagnostics — PHASE-0.57A workspace ACL contract probe.
 *
 * Read-only disk/loader/runtime wiring evidence. It deliberately does not
 * create notebooks, grants, public links, or call the provider gateway.
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_KG_Workspace_ACL', false ) ) {
	return;
}

final class BizCity_Probe_KG_Workspace_ACL implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.knowledge.kg_workspace_acl'; }
	public function label(): string { return 'KG Workspace ACL / Public Training Wiring'; }
	public function description(): string {
		return 'Verifies the PHASE-0.57A workspace ACL owner, public-link service, training seeder wiring, and read/write route guards without mutating business data.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 62; }
	public function icon(): string { return 'ShieldCheck'; }
	public function estimate_ms(): int { return 180; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_KG_Access' ) ) {
			return new WP_Error( 'kg_acl_missing', 'BizCity_KG_Access is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$failures = array();
		$root = defined( 'BIZCITY_KG_HUB_INCLUDES' ) ? BIZCITY_KG_HUB_INCLUDES : '';
		$required = array(
			'class-kg-access.php' => array( 'BizCity_KG_Access', array( 'readable_where', 'can_read_notebook', 'can_manage', 'grant_view', 'revoke_grant' ) ),
			'class-kg-public-link-service.php' => array( 'BizCity_KG_Public_Link_Service', array( 'create', 'resolve', 'revoke' ) ),
		);

		foreach ( $required as $file => $contract ) {
			$path = $root . $file;
			$file_ok = '' !== $root && is_readable( $path );
			$class_ok = class_exists( $contract[0] );
			$methods_ok = $class_ok;
			if ( $methods_ok ) {
				foreach ( $contract[1] as $method ) {
					if ( ! method_exists( $contract[0], $method ) ) {
						$methods_ok = false;
						break;
					}
				}
			}
			$ok = $file_ok && $class_ok && $methods_ok;
			$step = array(
				'label' => 'Disk/loader: ' . $file,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $ok ? 'Artifact, class and required methods are available.' : 'ACL contract artifact/class/method is missing.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $ok ) { $failures[] = 'contract_' . sanitize_key( $file ); }
		}

		$database_ok = class_exists( 'BizCity_KG_Database' )
			&& method_exists( 'BizCity_KG_Database', 'tbl_workspaces' )
			&& method_exists( 'BizCity_KG_Database', 'tbl_grants' )
			&& method_exists( 'BizCity_KG_Database', 'tbl_acl_log' );
		$registry_ok = class_exists( 'BizCity_Schema_Registry' )
			&& BizCity_Schema_Registry::is_registered( 'bizcity_kg_workspaces' )
			&& BizCity_Schema_Registry::is_registered( 'bizcity_kg_grants' )
			&& BizCity_Schema_Registry::is_registered( 'bizcity_kg_acl_log' );
		$step = array(
			'label' => 'Loader: per-blog ACL schema ownership',
			'status' => ( $database_ok && $registry_ok ) ? 'pass' : 'fail',
			'detail' => ( $database_ok && $registry_ok ) ? 'KG database helpers and central schema registry cover all ACL tables.' : 'ACL tables are not fully owned by the KG database/schema registry.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $database_ok || ! $registry_ok ) { $failures[] = 'acl_schema_ownership_missing'; }

		$predicate = BizCity_KG_Access::readable_notebooks_where( 7, 'twinbrain', 'nb.' );
		$predicate_ok = is_string( $predicate )
			&& false !== strpos( $predicate, 'owner_id' )
			&& false !== strpos( $predicate, 'bizcity_kg_workspaces' )
			&& false !== strpos( $predicate, 'bizcity_kg_grants' )
			&& false !== strpos( $predicate, 'visibility' );
		$step = array(
			'label' => 'Runtime: canonical readable notebook predicate',
			'status' => $predicate_ok ? 'pass' : 'fail',
			'detail' => $predicate_ok ? 'Owner, workspace visibility and grants are represented in one read predicate.' : 'Canonical ACL predicate is incomplete.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $predicate_ok ) { $failures[] = 'read_predicate_incomplete'; }

		$route_file = defined( 'BIZCITY_KG_HUB_INCLUDES' ) ? BIZCITY_KG_HUB_INCLUDES . 'class-kg-rest-controller.php' : '';
		$route_source = $route_file !== '' && is_readable( $route_file ) ? (string) file_get_contents( $route_file ) : '';
		$route_ok = false !== strpos( $route_source, "'/public-links'" )
			&& false !== strpos( $route_source, "'/workspaces/tree'" )
			&& false !== strpos( $route_source, 'assert_notebook_owner' )
			&& false !== strpos( $route_source, 'assert_notebook_readable' );
		$step = array(
			'label' => 'Disk: REST read/write boundary wiring',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok ? 'Workspace/public-link routes and notebook read/write guards are present.' : 'REST ACL route or guard wiring is incomplete.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $route_ok ) { $failures[] = 'rest_acl_wiring_missing'; }

		return array(
			'status' => empty( $failures ) ? 'pass' : 'fail',
			'summary' => empty( $failures ) ? 'PHASE-0.57A ACL wiring is present across disk, loader and read/write boundaries.' : 'PHASE-0.57A ACL wiring has missing contract evidence.',
			'error' => empty( $failures ) ? '' : implode( ', ', $failures ),
			'fix_hint' => empty( $failures ) ? '' : 'Load the KG ACL owner and register every ACL table/route before running runtime evidence.',
			'steps' => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', static function ( $probes ) {
	$probes[] = 'BizCity_Probe_KG_Workspace_ACL';
	return $probes;
} );
