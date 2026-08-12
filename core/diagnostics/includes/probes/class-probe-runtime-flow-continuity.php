<?php
/**
 * Diagnostics probe: PHASE-1.23 TwinCore runtime flow continuity.
 *
 * Read-only audit of bounded semantic runtime spans. It does not invoke a
 * provider, prompt builder, database query or event-stream write.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Runtime_Flow_Continuity', false ) ) {
	return;
}

final class BizCity_Probe_Runtime_Flow_Continuity implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'twinbrain.runtime.continuity'; }
	public function label(): string { return 'TwinCore runtime flow continuity'; }
	public function description(): string {
		return 'Audits parent-child semantic runtime spans and balanced enter/exit events without invoking AI or persistence.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 20; }
	public function icon(): string { return 'workflow'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Twin_Trace' ) ) {
			return new WP_Error( 'twin_trace_missing', 'BizCity_Twin_Trace chưa được load.' );
		}
		if ( ! method_exists( 'BizCity_Twin_Trace', 'runtime_entries' ) ) {
			return new WP_Error( 'runtime_span_api_missing', 'Runtime span API chưa sẵn sàng.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W4 - validate semantic
		// span structure only; an untouched request is WARN, not a false failure.
		$entries = BizCity_Twin_Trace::runtime_entries();
		$open = method_exists( 'BizCity_Twin_Trace', 'runtime_open_spans' )
			? BizCity_Twin_Trace::runtime_open_spans()
			: array();
		$ids = array();
		$invalid = array();
		$enter_count = 0;
		$exit_count = 0;
		$parent_edges = 0;
		$unknown_parents = array();
		$invalid_llm_edges = array();
		$invalid_memory_edges = array();
		foreach ( $entries as $entry ) {
			$data = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();
			$event_id = (string) ( $data['event_id'] ?? '' );
			$phase = (string) ( $data['phase'] ?? '' );
			if ( $event_id === '' || ! in_array( $phase, array( 'enter', 'exit' ), true ) || empty( $data['feature'] ) || empty( $data['operation'] ) ) {
				$invalid[] = array( 'event_id' => $event_id, 'phase' => $phase );
			}
			if ( $phase === 'enter' ) {
				$enter_count++;
				$ids[ $event_id ] = array(
					'enter'     => true,
					'exit'      => false,
					'feature'   => (string) ( $data['feature'] ?? '' ),
					'operation' => (string) ( $data['operation'] ?? '' ),
				);
			} elseif ( $phase === 'exit' ) {
				$exit_count++;
				if ( isset( $ids[ $event_id ] ) ) {
					$ids[ $event_id ]['exit'] = true;
				}
			}
			if ( ! empty( $data['parent_event_id'] ) ) {
				$parent_edges++;
				$parent_id = (string) $data['parent_event_id'];
				if ( ! isset( $ids[ $parent_id ] ) || empty( $ids[ $parent_id ]['enter'] ) ) {
					$unknown_parents[] = $parent_id;
				}
				if ( (string) ( $data['feature'] ?? '' ) === 'llm_client' ) {
					$child_operation = (string) ( $data['operation'] ?? '' );
					$expected_parent = $child_operation === 'chat_gateway'
						? 'chat'
						: ( $child_operation === 'chat_stream_gateway' ? 'chat_stream' : '' );
					if ( $expected_parent !== ''
						&& ( ! isset( $ids[ $parent_id ] ) || $ids[ $parent_id ]['feature'] !== 'llm_client' || $ids[ $parent_id ]['operation'] !== $expected_parent ) ) {
						$invalid_llm_edges[] = $event_id;
					}
				}
				if ( (string) ( $data['feature'] ?? '' ) === 'memory' ) {
					$child_operation = (string) ( $data['operation'] ?? '' );
					if ( in_array( $child_operation, array( 'recall_unified', 'recall_legacy' ), true )
						&& ( ! isset( $ids[ $parent_id ] ) || $ids[ $parent_id ]['feature'] !== 'memory' || $ids[ $parent_id ]['operation'] !== 'recall' ) ) {
						$invalid_memory_edges[] = $event_id;
					}
				}
			}
		}
		$unbalanced = array();
		foreach ( $ids as $event_id => $pair ) {
			if ( empty( $pair['enter'] ) || empty( $pair['exit'] ) ) {
				$unbalanced[] = $event_id;
			}
		}

		$ctx->emit_step( array(
			'label'  => 'Runtime · TwinCore span structure',
			'status' => empty( $invalid ) && empty( $unbalanced ) && empty( $open ) && empty( $unknown_parents ) && empty( $invalid_llm_edges ) && empty( $invalid_memory_edges )
				? ( $enter_count > 0 ? 'pass' : 'warn' )
				: 'fail',
			'detail' => self::json_detail( array(
				'events'       => count( $entries ),
				'enter_count'  => $enter_count,
				'exit_count'   => $exit_count,
				'parent_edges' => $parent_edges,
				'unknown_parents' => array_slice( $unknown_parents, 0, 20 ),
				'invalid_llm_edges' => array_slice( $invalid_llm_edges, 0, 20 ),
				'invalid_memory_edges' => array_slice( $invalid_memory_edges, 0, 20 ),
				'unbalanced'   => array_slice( $unbalanced, 0, 20 ),
				'open_spans'   => array_slice( $open, 0, 20 ),
				'invalid'      => array_slice( $invalid, 0, 20 ),
			) ),
		) );

		$pass = $enter_count > 0 && empty( $invalid ) && empty( $unbalanced ) && empty( $open ) && empty( $unknown_parents ) && empty( $invalid_llm_edges ) && empty( $invalid_memory_edges );
		return array(
			'status'   => $pass ? 'pass' : ( empty( $invalid ) && empty( $unbalanced ) && empty( $open ) && empty( $unknown_parents ) && empty( $invalid_llm_edges ) && empty( $invalid_memory_edges ) ? 'warn' : 'fail' ),
			'summary'  => $pass
				? 'TwinCore semantic runtime spans are balanced and structurally connected.'
				: ( empty( $entries ) ? 'No TwinCore runtime span was observed in this request; rerun on a feature runtime route.' : 'TwinCore runtime span evidence is incomplete or unbalanced.' ),
			'fix_hint' => $pass ? '' : 'Run the probe on the feature route and inspect enter/exit/parent_event_id evidence before adding more instrumentation.',
		);
	}

	private static function json_detail( $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public function cleanup(): void {}

	public static function register( $list ) {
		$list[] = 'BizCity_Probe_Runtime_Flow_Continuity';
		return $list;
	}
}

if ( false === has_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_Runtime_Flow_Continuity', 'register' ) ) ) {
	add_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_Runtime_Flow_Continuity', 'register' ) );
}