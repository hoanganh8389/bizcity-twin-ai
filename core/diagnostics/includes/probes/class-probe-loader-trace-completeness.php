<?php
/**
 * Diagnostics probe: PHASE-1.23 loader trace completeness.
 *
 * Read-only structural assertion for the observe-only QM loader schema. It does
 * not load feature runtime, query DB, call providers or enforce ownership.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Loader_Trace_Completeness', false ) ) {
	return;
}

final class BizCity_Probe_Loader_Trace_Completeness implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.loader.trace_completeness'; }
	public function label(): string { return 'PHASE-1.23 loader trace completeness'; }
	public function description(): string {
		return 'Checks observe-only loader schema v2, callback provenance, file anchors, request context and registration evidence.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 17; }
	public function icon(): string { return 'activity'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Diagnostics_Loader_Hook_Panel' ) ) {
			return new WP_Error( 'loader_trace_panel_missing', 'Loader trace panel chưa được load.' );
		}
		if ( ! method_exists( 'BizCity_Diagnostics_Loader_Hook_Panel', 'snapshots' ) ) {
			return new WP_Error( 'loader_trace_snapshot_missing', 'Loader trace snapshot API chưa sẵn sàng.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W3 - assert evidence shape only; never block runtime loading.
		$snapshots = BizCity_Diagnostics_Loader_Hook_Panel::snapshots();
		if ( empty( $snapshots ) || ! is_array( $snapshots ) ) {
			$ctx->emit_step( array(
				'label'  => 'Runtime · loader snapshots exist',
				'status' => 'fail',
				'detail' => 'No lifecycle snapshot was captured in this request.',
			) );
			return array(
				'status'   => 'fail',
				'summary'  => 'Loader trace schema cannot be evaluated because no snapshot exists.',
				'fix_hint' => 'Run the probe on a Diagnostics/QM request where the loader panel is active.',
			);
		}

		$allowed_parent_status = array( 'known_wrapper', 'known_entrypoint', 'inferred_order', 'unknown_parent' );
		$missing_context = 0;
		$invalid_schema = 0;
		$invalid_parent = 0;
		$callback_rows = 0;
		$callback_locations = 0;
		$registration_rows = 0;
		$file_anchor_rows = 0;
		$boot_state_rows = 0;
		$boot_state_invalid = 0;
		$boot_conflicts = 0;
		$boot_observe_only = 0;
		$allowed_states = array( 'ABSENT', 'DECLARED', 'CONTRACT_READY', 'BOOTSTRAPPED', 'RUNTIME_READY', 'FAILED' );

		foreach ( $snapshots as $phase => $snapshot ) {
			if ( ! is_array( $snapshot ) || ( $snapshot['schema'] ?? '' ) !== 'bizcity.loader.v2' ) {
				$invalid_schema++;
			}
			$context = isset( $snapshot['context'] ) && is_array( $snapshot['context'] )
				? $snapshot['context']
				: array();
			if ( empty( $context['surface'] ) || ! array_key_exists( 'route_or_screen', $context ) ) {
				$missing_context++;
			}
			$parent_status = isset( $snapshot['require_parent_status'] )
				? (string) $snapshot['require_parent_status']
				: '';
			if ( ! in_array( $parent_status, $allowed_parent_status, true ) ) {
				$invalid_parent++;
			}
			if ( ! empty( $snapshot['first_new_file_by_group'] ) ) {
				$file_anchor_rows++;
			}
			if ( isset( $snapshot['registration_delta'] ) && is_array( $snapshot['registration_delta'] ) ) {
				$registration_rows++;
			}
			if ( ( $snapshot['boot_state_status'] ?? '' ) === 'observe_only' ) {
				$boot_observe_only++;
			}
			$boot_delta = isset( $snapshot['boot_state_delta'] ) && is_array( $snapshot['boot_state_delta'] )
				? $snapshot['boot_state_delta']
				: array();
			foreach ( (array) ( $boot_delta['records'] ?? array() ) as $record ) {
				$boot_state_rows++;
				if ( empty( $record['feature_id'] )
					|| empty( $record['canonical_path'] )
					|| ! in_array( (string) ( $record['state'] ?? '' ), $allowed_states, true )
					|| (int) ( $record['claim_count'] ?? 0 ) < 1 ) {
					$boot_state_invalid++;
				}
			}
			foreach ( (array) ( $boot_delta['events'] ?? array() ) as $event ) {
				if ( in_array( (string) ( $event['event'] ?? '' ), array( 'duplicate_owner', 'version_conflict' ), true ) ) {
					$boot_conflicts++;
				}
			}
			foreach ( (array) ( $snapshot['hooks'] ?? array() ) as $hook ) {
				$callback_rows++;
				if ( ! empty( $hook['callback_file_relative'] ) && (int) ( $hook['callback_start_line'] ?? 0 ) > 0 ) {
					$callback_locations++;
				}
			}
		}

		$ctx->emit_step( array(
			'label'  => 'Runtime · loader schema v2 and request context',
			'status' => $invalid_schema === 0 && $missing_context === 0 ? 'pass' : 'fail',
			'detail' => self::json_detail( array(
				'phases'          => count( $snapshots ),
				'invalid_schema'  => $invalid_schema,
				'missing_context' => $missing_context,
			) ),
		) );
		$ctx->emit_step( array(
			'label'  => 'Runtime · callback provenance and file anchors',
			'status' => $invalid_parent === 0 ? ( $callback_rows === 0 ? 'warn' : 'pass' ) : 'fail',
			'detail' => self::json_detail( array(
				'callback_rows'       => $callback_rows,
				'callback_locations'  => $callback_locations,
				'file_anchor_phases'  => $file_anchor_rows,
				'parent_status_errors' => $invalid_parent,
			) ),
		) );
		$ctx->emit_step( array(
			'label'  => 'Runtime · registration delta is present',
			'status' => $registration_rows === count( $snapshots ) ? 'pass' : 'fail',
			'detail' => self::json_detail( array(
				'phases'             => count( $snapshots ),
				'registration_rows'  => $registration_rows,
			) ),
		) );
		$ctx->emit_step( array(
			'label'  => 'Runtime · canonical boot ownership is observe-only',
			'status' => $boot_state_invalid > 0 || $boot_conflicts > 0
				? 'fail'
				: ( $boot_state_rows === 0 ? 'warn' : ( $boot_observe_only > 0 ? 'pass' : 'warn' ) ),
			'detail' => self::json_detail( array(
				'boot_state_rows'  => $boot_state_rows,
				'boot_observe_only' => $boot_observe_only,
				'invalid_records'  => $boot_state_invalid,
				'conflicts'        => $boot_conflicts,
			) ),
		) );

		$pass = $invalid_schema === 0 && $missing_context === 0 && $invalid_parent === 0
			&& $registration_rows === count( $snapshots )
			&& $boot_state_invalid === 0 && $boot_conflicts === 0;
		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'Loader schema v2 is structurally complete for the captured phases; unknown causal parents are explicit.'
				: 'Loader trace schema is incomplete; inspect the emitted evidence steps before ownership enforcement.',
			'fix_hint' => $pass ? '' : 'Keep ownership observe-only and add the missing context, registration or provenance fields.',
		);
	}

	private static function json_detail( $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public function cleanup(): void {}

	public static function register( $list ) {
		$list[] = 'BizCity_Probe_Loader_Trace_Completeness';
		return $list;
	}
}

if ( false === has_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_Loader_Trace_Completeness', 'register' ) ) ) {
	add_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_Loader_Trace_Completeness', 'register' ) );
}
