<?php
/**
 * Diagnostics probe: PHASE-1.23 canonical loader ownership.
 *
 * Read-only audit of the observe-only ownership registry. It does not reject a
 * secondary loader and does not change runtime behavior.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Loader_Ownership', false ) ) {
	return;
}

final class BizCity_Probe_Loader_Ownership implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.loader.ownership'; }
	public function label(): string { return 'PHASE-1.23 canonical loader ownership'; }
	public function description(): string {
		return 'Audits canonical feature claims, monotonic boot state, physical path conflicts and secondary loader attempts.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 18; }
	public function icon(): string { return 'shield-check'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Loader_Ownership_Registry' ) ) {
			return new WP_Error( 'loader_ownership_registry_missing', 'Canonical loader ownership registry chưa được load.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W3 - audit registry state
		// without enforcing ownership or loading any feature runtime.
		$snapshot = BizCity_Loader_Ownership_Registry::snapshot();
		$records = isset( $snapshot['records'] ) && is_array( $snapshot['records'] )
			? $snapshot['records']
			: array();
		$events = isset( $snapshot['events'] ) && is_array( $snapshot['events'] )
			? $snapshot['events']
			: array();
		$mode = isset( $snapshot['mode'] ) ? (string) $snapshot['mode'] : '';
		$allowed_states = array( 'DECLARED', 'CONTRACT_READY', 'BOOTSTRAPPED', 'RUNTIME_READY', 'FAILED' );
		$invalid = array();
		$conflicts = array();
		$secondary_claims = 0;
		$state_counts = array();

		foreach ( $records as $feature_id => $record ) {
			$state = (string) ( $record['state'] ?? '' );
			if ( $state === '' || ! in_array( $state, $allowed_states, true ) || empty( $record['canonical_path'] ) || empty( $record['owner_source'] ) ) {
				$invalid[] = (string) $feature_id;
			}
			if ( ! isset( $state_counts[ $state ] ) ) {
				$state_counts[ $state ] = 0;
			}
			$state_counts[ $state ]++;
			$secondary_claims += count( (array) ( $record['secondary_sources'] ?? array() ) );
		}
		foreach ( $events as $event ) {
			if ( in_array( (string) ( $event['event'] ?? '' ), array( 'duplicate_owner', 'version_conflict' ), true ) ) {
				$conflicts[] = array(
					'event'   => (string) ( $event['event'] ?? '' ),
					'feature' => (string) ( $event['feature_id'] ?? '' ),
					'source'  => (string) ( $event['source'] ?? '' ),
					'reason'  => (string) ( $event['reason'] ?? '' ),
				);
			}
		}

		$ctx->emit_step( array(
			'label'  => 'Runtime · canonical owner records',
			'status' => empty( $invalid ) && 'observe_only' === $mode ? 'pass' : 'fail',
			'detail' => self::json_detail( array(
				'mode'           => $mode,
				'feature_count'  => count( $records ),
				'invalid_records' => $invalid,
				'states'         => $state_counts,
			) ),
		) );
		$ctx->emit_step( array(
			'label'  => 'Runtime · secondary and conflicting owner attempts',
			'status' => empty( $conflicts ) ? 'pass' : 'fail',
			'detail' => self::json_detail( array(
				'secondary_claims' => $secondary_claims,
				'conflicts'        => $conflicts,
			) ),
		) );

		$pass = empty( $invalid ) && empty( $conflicts ) && 'observe_only' === $mode;
		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'Canonical owner records are valid and conflict-free in observe-only mode.'
				: 'Canonical ownership has invalid records or conflicts; keep enforcement disabled and inspect the evidence.',
			'fix_hint' => $pass ? '' : 'Resolve path/version ownership evidence before enabling any blocking behavior.',
		);
	}

	private static function json_detail( $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public function cleanup(): void {}

	public static function register( $list ) {
		$list[] = 'BizCity_Probe_Loader_Ownership';
		return $list;
	}
}

if ( false === has_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_Loader_Ownership', 'register' ) ) ) {
	add_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_Loader_Ownership', 'register' ) );
}
