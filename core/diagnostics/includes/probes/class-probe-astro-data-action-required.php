<?php
/**
 * BizCity Diagnostics — astro.data_action_required_event probe (PHASE-FAA2-TWINBRAIN).
 *
 * 3-layer evidence (R-DDV):
 * - Disk: runtime source contains SSE + emit_event wiring for astro_data_action_required.
 * - Loader: runtime + event bus classes/methods are available.
 * - Runtime: synthetic emit through Runtime::emit_event() is captured on
 *   `bizcity_twin_event` with expected payload shape.
 *
 * This probe is intentionally lightweight (no LLM call).
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Astro_Data_Action_Required', false ) ) {
	return;
}

final class BizCity_Probe_Astro_Data_Action_Required implements BizCity_Diagnostics_Probe {

	public function id(): string       { return 'astro.data_action_required_event'; }
	public function label(): string    { return 'Astro Data Action Required Event'; }
	public function description(): string {
		return 'Verify astro_data_action_required dispatch path and payload evidence (failed_keys/stale_keys).';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int       { return 47; }
	public function icon(): string     { return 'alert-triangle'; }
	public function estimate_ms(): int { return 240; }

	public function precondition() {
		if ( ! defined( 'BIZCITY_TWINBRAIN_DIR' ) ) {
			return 'BIZCITY_TWINBRAIN_DIR chưa định nghĩa — twinbrain chưa active.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-09 Johnny Chu] PHASE-FAA2-TWINBRAIN A16 — DDV probe for astro_data_action_required runtime evidence.
		$steps    = array();
		$failures = array();
		$evidence = array();

		$runtime_file = BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-runtime.php';
		$runtime_src  = file_exists( $runtime_file ) ? (string) file_get_contents( $runtime_file ) : '';

		$disk_ok = ( $runtime_src !== '' );
		$steps[] = array(
			'layer' => 'disk',
			'ok'    => $disk_ok,
			'msg'   => $disk_ok
				? 'Runtime file exists: class-twinbrain-runtime.php'
				: 'Runtime file missing: ' . $runtime_file,
		);
		if ( ! $disk_ok ) {
			$failures[] = 'runtime_file_missing';
		}

		$has_sse_emit    = ( strpos( $runtime_src, "sse->emit( 'astro_data_action_required'" ) !== false );
		$has_event_emit  = ( strpos( $runtime_src, "emit_event( 'astro_data_action_required'" ) !== false );
		$has_failed_keys = ( strpos( $runtime_src, "'failed_keys'" ) !== false );
		$has_stale_keys  = ( strpos( $runtime_src, "'stale_keys'" ) !== false );

		$disk_symbol_ok = $has_sse_emit && $has_event_emit && $has_failed_keys && $has_stale_keys;
		$steps[] = array(
			'layer' => 'disk',
			'ok'    => $disk_symbol_ok,
			'msg'   => $disk_symbol_ok
				? 'Event symbols present: SSE emit + event bus emit + failed_keys/stale_keys payload.'
				: 'Missing one or more astro_data_action_required symbols in runtime source.',
		);
		if ( ! $disk_symbol_ok ) {
			$failures[] = 'runtime_symbols_missing';
		}

		$class_ok  = class_exists( 'BizCity_TwinBrain_Runtime' );
		$bus_ok    = class_exists( 'BizCity_Twin_Event_Bus' );
		$method_ok = $class_ok && method_exists( 'BizCity_TwinBrain_Runtime', 'emit_event' );

		$steps[] = array(
			'layer' => 'loader',
			'ok'    => $class_ok,
			'msg'   => $class_ok
				? 'Class BizCity_TwinBrain_Runtime declared.'
				: 'Class BizCity_TwinBrain_Runtime not loaded.',
		);
		if ( ! $class_ok ) {
			$failures[] = 'runtime_class_missing';
		}

		$steps[] = array(
			'layer' => 'loader',
			'ok'    => ( $bus_ok && $method_ok ),
			'msg'   => ( $bus_ok && $method_ok )
				? 'Event bus + Runtime::emit_event() available.'
				: 'Event bus missing or Runtime::emit_event() missing.',
		);
		if ( ! $bus_ok || ! $method_ok ) {
			$failures[] = 'runtime_loader_missing';
		}

		$captured = array();
		$listener = function ( $event_key, $payload ) use ( &$captured ) {
			if ( (string) $event_key !== 'astro_data_action_required' ) {
				return;
			}
			$captured[] = is_array( $payload ) ? $payload : array( '_raw' => $payload );
		};

		if ( function_exists( 'add_action' ) ) {
			add_action( 'bizcity_twin_event', $listener, 999, 2 );
		}

		$runtime_exception = '';
		$probe_trace_id    = 'probe-astro-action-' . substr( md5( (string) microtime( true ) ), 0, 8 );
		try {
			if ( $class_ok && $method_ok && method_exists( 'BizCity_TwinBrain_Runtime', 'instance' ) ) {
				$inst = BizCity_TwinBrain_Runtime::instance();
				$rm   = new ReflectionMethod( 'BizCity_TwinBrain_Runtime', 'emit_event' );
				$rm->setAccessible( true );
				$rm->invoke( $inst, 'astro_data_action_required', array(
					'trace_id'         => $probe_trace_id,
					'reason'           => 'probe_runtime',
					'needs_birth_data' => false,
					'failed_keys'      => array( 'natal_chart' ),
					'stale_keys'       => array( 'transit_daily' ),
					'actions'          => array(
						array(
							'label'   => 'Probe Action',
							'url'     => 'https://example.com',
							'variant' => 'secondary',
						),
					),
				) );
			}
		} catch ( \Throwable $e ) {
			$runtime_exception = $e->getMessage();
		}

		if ( function_exists( 'remove_action' ) ) {
			remove_action( 'bizcity_twin_event', $listener, 999 );
		}

		$runtime_emit_ok = ! empty( $captured ) && $runtime_exception === '';
		$steps[] = array(
			'layer' => 'runtime',
			'ok'    => $runtime_emit_ok,
			'msg'   => $runtime_emit_ok
				? 'Synthetic emit captured on bizcity_twin_event (astro_data_action_required).'
				: ( $runtime_exception !== ''
					? 'Synthetic emit exception: ' . $runtime_exception
					: 'No astro_data_action_required event captured from synthetic emit.' ),
		);
		if ( ! $runtime_emit_ok ) {
			$failures[] = 'runtime_event_missing';
		}

		$shape_ok = false;
		if ( $runtime_emit_ok ) {
			$last = $captured[ count( $captured ) - 1 ];
			$shape_ok = is_array( $last )
				&& isset( $last['reason'] )
				&& array_key_exists( 'failed_keys', $last )
				&& array_key_exists( 'stale_keys', $last )
				&& isset( $last['actions'] )
				&& is_array( $last['failed_keys'] )
				&& is_array( $last['stale_keys'] )
				&& is_array( $last['actions'] );

			$steps[] = array(
				'layer' => 'runtime',
				'ok'    => $shape_ok,
				'msg'   => $shape_ok
					? 'Captured payload shape valid (reason/actions/failed_keys/stale_keys).'
					: 'Captured payload shape invalid for astro_data_action_required.',
			);
			if ( ! $shape_ok ) {
				$failures[] = 'runtime_payload_shape';
			}
			$evidence['captured_payload'] = $last;
		}

		// Informational: this event currently emits through legacy dispatch path,
		// so persisted rows in event_stream may be zero on many sites.
		if ( class_exists( 'BizCity_Twin_Event_Stream_Schema' ) ) {
			global $wpdb;
			$tbl = BizCity_Twin_Event_Stream_Schema::table();
			if ( function_exists( 'bizcity_tbl_exists' ) && bizcity_tbl_exists( $tbl ) ) {
				$recent_count = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$tbl} WHERE event_type = %s AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)",
					'astro_data_action_required'
				) );
				$steps[] = array(
					'layer' => 'runtime',
					'ok'    => true,
					'msg'   => $recent_count > 0
						? 'Event stream evidence: found ' . $recent_count . ' row(s) in last 7 days.'
						: 'Event stream evidence: 0 row(s) in last 7 days (legacy dispatch may be non-persisted).',
				);
				$evidence['event_stream_recent_count_7d'] = $recent_count;
			}
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'steps'    => $steps,
				'summary'  => 'astro_data_action_required issue(s): ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Check stream_astro_mode emit points and Runtime::emit_event dispatch wiring.',
				'evidence' => $evidence,
			);
		}

		return array(
			'status'   => 'pass',
			'steps'    => $steps,
			'summary'  => 'astro_data_action_required runtime evidence OK (dispatch + payload shape).',
			'evidence' => $evidence,
		);
	}

	public function cleanup(): void {
		// Read-only probe.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Astro_Data_Action_Required';
	return $list;
} );
