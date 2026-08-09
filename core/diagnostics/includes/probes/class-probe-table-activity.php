<?php
/**
 * BizCity Diagnostics — table activity telemetry probe.
 *
 * Read-only DDV for the lightweight query observer and the enriched table
 * inventory contract. It never inserts synthetic business data.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-08-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Table_Activity', false ) ) {
	return;
}

final class BizCity_Probe_Table_Activity implements BizCity_Diagnostics_Probe {

	public function id(): string {
		return 'core.diagnostics.table_activity';
	}

	public function label(): string {
		return 'Table activity telemetry';
	}

	public function description(): string {
		return 'Kiểm tra runtime read/write telemetry, source attribution và activity contract của Table Inventory.';
	}

	public function severity(): string {
		return 'warning';
	}

	public function order(): int {
		return 8;
	}

	public function icon(): string {
		return 'activity';
	}

	public function estimate_ms(): int {
		return 150;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_Diagnostics_Table_Activity' ) ) {
			return new WP_Error( 'table_activity_missing', 'Table activity observer chưa load.' );
		}
		if ( ! class_exists( 'BizCity_Diagnostics_Table_Inspector' ) ) {
			return new WP_Error( 'table_inspector_missing', 'Table inspector chưa load.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-01 Johnny Chu] PHASE-1.23-TABLE-ACTIVITY — verify Disk/Loader/Runtime telemetry contract without mutating business data.
		$hooked = has_filter(
			'query',
			array( 'BizCity_Diagnostics_Table_Activity', 'observe_query' )
		) !== false;
		$snapshot = BizCity_Diagnostics_Table_Activity::snapshot();
		$rows = BizCity_Diagnostics_Table_Inspector::inspect_all();
		$contract_ok = false;
		foreach ( $rows as $row ) {
			if ( isset( $row['activity_status'], $row['activity_stability'], $row['last_used_at'], $row['last_read_at'], $row['last_write_at'] ) ) {
				$contract_ok = true;
				break;
			}
		}

		$ctx->emit_step( array(
			'label'  => 'Loader · query hook',
			'status' => $hooked ? 'pass' : 'fail',
			'detail' => $hooked ? 'wpdb query observer registered.' : 'Observer filter is not registered.',
		) );
		$ctx->emit_step( array(
			'label'  => 'Runtime · snapshot shape',
			'status' => is_array( $snapshot ) ? 'pass' : 'fail',
			'detail' => sprintf( '%d table activity records currently persisted.', count( $snapshot ) ),
		) );
		$ctx->emit_step( array(
			'label'  => 'Runtime · inspector contract',
			'status' => $contract_ok ? 'pass' : 'fail',
			'detail' => $contract_ok ? 'activity_status + stability + read/write timestamps present.' : 'Enriched table fields are missing.',
		) );

		if ( ! $hooked || ! $contract_ok ) {
			return array(
				'status'  => 'fail',
				'summary' => 'Table activity telemetry contract incomplete.',
				'error'   => 'The observer or enriched Inspector fields are unavailable.',
				'fix_hint'=> 'Check PHASE-1.23-TABLE-ACTIVITY bootstrap and Table Inspector integration.',
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => sprintf( 'Telemetry ready · %d persisted table records.', count( $snapshot ) ),
		);
	}

	public function cleanup(): void {
		// Read-only probe — no artifacts to clean.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Table_Activity';
	return $list;
} );
