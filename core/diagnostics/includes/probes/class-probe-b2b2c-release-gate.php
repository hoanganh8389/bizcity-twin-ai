<?php
/**
 * Read-only H10 release-gate probe for B2B2C commerce coverage and rollback markers.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_B2B2C_Release_Gate', false ) ) {
	return;
}

final class BizCity_Probe_B2B2C_Release_Gate implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-09-05 03:20 PM Johnny Chu - Chu Hoàng Anh] B2C-H10 - identify B2B2C canary, rollback and release gate contract.
		return 'b2b2c.checkout.release_gate';
	}

	public function label(): string {
		return 'B2B2C release gate';
	}

	public function description(): string {
		return 'Checks registered H0-H9 probe coverage, rollback-boundary markers and explicit focused-versus-complete evidence without mutating commerce state.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 31;
	}

	public function icon(): string {
		return 'badge-check';
	}

	public function estimate_ms(): int {
		return 160;
	}

	public function precondition() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
		if ( ! in_array( $host, array( 'bizcity.vn', 'www.bizcity.vn' ), true ) ) {
			return 'not_applicable_b2_client: B2B2C release gate is owned by the B1 Hub.';
		}
		if ( ! class_exists( 'BizCity_Router_Commerce_Service' ) ) {
			return new WP_Error( 'release_gate_loader_missing', 'Commerce Service is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-05 03:20 PM Johnny Chu - Chu Hoàng Anh] B2C-H10 - verify release catalog/rollback markers without claiming canary execution.
		// [2026-09-06 05:05 PM Johnny Chu - Chu Hoàng Anh] B2C-H10 — documentation files are operator-deferred; release validation must not depend on deployed roadmap/checklist availability.
		$failures = array();
		$router_dir = defined( 'BIZCITY_LLM_ROUTER_DIR' ) ? rtrim( (string) BIZCITY_LLM_ROUTER_DIR, '/\\' ) : '';
		$required_ids = array(
			'b2b2c.checkout.billing_context',
			'b2b2c.account.commerce_exact_key',
			'b2b2c.checkout.license_ledger',
			'b2b2c.checkout.entitlement_projector',
			'b2b2c.checkout.expiry_lifecycle',
			'b2b2c.account.member_license_history',
			'b2b2c.checkout.master_admin',
			'b2b2c.checkout.commerce_dashboard',
			'b2b2c.checkout.multi_hub_authority',
		);
		// [2026-09-06 04:45 PM Johnny Chu - Chu Hoàng Anh] B2C-H10 — match the catalog by its actual registered probe filenames instead of synthetic ID spellings.
		$required_markers = array(
			'b2b2c.checkout.billing_context'      => 'class-probe-b2b2c-checkout-billing-context.php',
			'b2b2c.account.commerce_exact_key'   => 'class-probe-b2b2c-commerce-exact-key.php',
			'b2b2c.checkout.license_ledger'      => 'class-probe-b2b2c-license-ledger.php',
			'b2b2c.checkout.entitlement_projector' => 'class-probe-b2b2c-entitlement-projector.php',
			'b2b2c.checkout.expiry_lifecycle'    => 'class-probe-b2b2c-expiry-lifecycle.php',
			'b2b2c.account.member_license_history' => 'class-probe-b2b2c-member-license-history.php',
			'b2b2c.checkout.master_admin'         => 'class-probe-b2b2c-master-admin.php',
			'b2b2c.checkout.commerce_dashboard'   => 'class-probe-b2b2c-commerce-dashboard.php',
			'b2b2c.checkout.multi_hub_authority'  => 'class-probe-b2b2c-multi-hub-authority.php',
		);
		$missing = array();
		$catalog_source = defined( 'BIZCITY_DIAGNOSTICS_DIR' ) ? rtrim( (string) BIZCITY_DIAGNOSTICS_DIR, '/\\' ) . '/bootstrap.php' : '';
		$catalog = is_readable( $catalog_source ) ? (string) file_get_contents( $catalog_source ) : '';
		foreach ( $required_ids as $probe_id ) {
			$marker = isset( $required_markers[ $probe_id ] ) ? $required_markers[ $probe_id ] : '';
			if ( $marker === '' || strpos( $catalog, $marker ) === false ) {
				$missing[] = $probe_id;
			}
		}
		$catalog_ok = empty( $missing );
		$ctx->emit_step( array( 'label' => 'Disk - H0-H9 probe catalog', 'status' => $catalog_ok ? 'pass' : 'fail', 'detail' => $catalog_ok ? 'Required B2B2C probe registrations are present in the diagnostics bootstrap.' : 'Missing required probe registrations: ' . implode( ', ', $missing ) ) );
		if ( ! $catalog_ok ) {
			$failures[] = 'required_probe_registration_missing';
		}

		$ctx->emit_step( array( 'label' => 'Documentation - rollback boundary', 'status' => 'deferred', 'detail' => 'Roadmap/checklist files are intentionally outside the deployed probe contract; rollback is validated from runtime switches below.' ) );

		$commerce_file = $router_dir . '/includes/commerce/class-router-commerce-service.php';
		$account_file  = $router_dir . '/includes/class-router-account-experience.php';
		$commerce_source = is_readable( $commerce_file ) ? (string) file_get_contents( $commerce_file ) : '';
		$account_source  = is_readable( $account_file ) ? (string) file_get_contents( $account_file ) : '';
		$rollback_code_ok = method_exists( 'BizCity_Router_Commerce_Service', 'is_feature_enabled' )
			&& strpos( $commerce_source, 'OPTION_CHECKOUT_CONTEXT_ENABLED' ) !== false
			&& strpos( $commerce_source, 'OPTION_LICENSE_PROJECTOR_ENABLED' ) !== false
			&& strpos( $commerce_source, "is_feature_enabled( 'license_projector' )" ) !== false
			&& strpos( $account_source, "is_feature_enabled( 'checkout_context' )" ) !== false;
		$ctx->emit_step( array( 'label' => 'Disk/Loader - rollback switches', 'status' => $rollback_code_ok ? 'pass' : 'fail', 'detail' => $rollback_code_ok ? 'Checkout-context and license-projector switches are implemented and wired to their runtime owners.' : 'One or more rollback switches are missing from the runtime owners.' ) );
		if ( ! $rollback_code_ok ) {
			$failures[] = 'rollback_switches_missing';
		}

		$ctx->emit_step( array( 'label' => 'Documentation - release status', 'status' => 'deferred', 'detail' => 'Release status is intentionally not inferred from unavailable roadmap/checklist files; canary and complete DDV remain pending.' ) );

		if ( ! empty( $failures ) ) {
			return array( 'status' => 'fail', 'summary' => 'B2B2C release gate contract failed: ' . implode( ', ', array_unique( $failures ) ), 'error' => implode( '; ', array_unique( $failures ) ), 'fix_hint' => 'Restore the fixed H0-H9 probe catalog and rollback boundary before running canary/release validation.' );
		}
		return array( 'status' => 'pass', 'summary' => 'Release gate catalog and runtime rollback switches passed; roadmap/checklist validation is deferred by operator request, and canary, rollback rehearsal and full complete-batch DDV remain pending.', 'canary_status' => 'pending', 'coverage_status' => 'focused_only', 'documentation_status' => 'deferred' );
	}

	public function cleanup(): void {
		// Read-only probe: no persistent artifacts to clean.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_B2B2C_Release_Gate';
	return $list;
} );
