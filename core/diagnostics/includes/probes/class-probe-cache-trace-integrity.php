<?php
/**
 * Diagnostics probe: PHASE-1.23 cache semantic trace integrity.
 *
 * Read-only structural assertion for opt-in BizCity_Cache trace events. It does
 * not force a cache operation or expose raw keys/values.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Cache_Trace_Integrity', false ) ) {
	return;
}

final class BizCity_Probe_Cache_Trace_Integrity implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.cache.trace_integrity'; }
	public function label(): string { return 'BizCity cache semantic trace integrity'; }
	public function description(): string {
		return 'Audits opt-in cache group/key-scope, hit/miss, TTL and invalidation events without exposing cache values.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 21; }
	public function icon(): string { return 'database-zap'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Twin_Trace' ) || ! method_exists( 'BizCity_Twin_Trace', 'runtime_entries' ) ) {
			return new WP_Error( 'cache_trace_api_missing', 'Runtime trace API chưa sẵn sàng.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W5 - validate cache
		// semantic event shape; no cache read/write is triggered by this probe.
		$entries = BizCity_Twin_Trace::runtime_entries();
		$cache_events = array();
		$invalid = array();
		$operations = array( 'get', 'set', 'delete', 'flush_group' );
		foreach ( $entries as $entry ) {
			$data = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();
			if ( (string) ( $data['feature'] ?? '' ) !== 'cache' ) {
				continue;
			}
			$cache_events[] = $data;
			if ( ! in_array( (string) ( $data['operation'] ?? '' ), $operations, true )
				|| empty( $data['cache_group'] )
				|| empty( $data['key_signature'] )
				|| (int) ( $data['blog_id'] ?? 0 ) < 0 ) {
				$invalid[] = array(
					'event_id' => (string) ( $data['event_id'] ?? '' ),
					'operation' => (string) ( $data['operation'] ?? '' ),
				);
			}
			if ( isset( $data['key'] ) || isset( $data['value'] ) || isset( $data['raw_key'] ) ) {
				$invalid[] = array( 'event_id' => (string) ( $data['event_id'] ?? '' ), 'reason' => 'raw_cache_field' );
			}
		}

		$ctx->emit_step( array(
			'label'  => 'Runtime · cache semantic events are scoped and redacted',
			'status' => empty( $cache_events ) ? 'warn' : ( empty( $invalid ) ? 'pass' : 'fail' ),
			'detail' => self::json_detail( array(
				'events'  => count( $cache_events ),
				'invalid' => array_slice( $invalid, 0, 20 ),
			) ),
		) );

		$pass = ! empty( $cache_events ) && empty( $invalid );
		return array(
			'status'   => $pass ? 'pass' : ( empty( $invalid ) ? 'warn' : 'fail' ),
			'summary'  => $pass
				? 'Cache semantic events contain only scoped hashed keys and safe operation metadata.'
				: ( empty( $cache_events ) ? 'No cache semantic event was observed; enable forensic cache trace and rerun.' : 'Cache semantic trace contains invalid or unsafe fields.' ),
			'fix_hint' => $pass ? '' : 'Use `?bizcity_qm_probe=1` or BIZCITY_TWIN_CACHE_TRACE for an opt-in cache trace; never log raw keys or values.',
		);
	}

	private static function json_detail( $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public function cleanup(): void {}

	public static function register( $list ) {
		$list[] = 'BizCity_Probe_Cache_Trace_Integrity';
		return $list;
	}
}

if ( false === has_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_Cache_Trace_Integrity', 'register' ) ) ) {
	add_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_Cache_Trace_Integrity', 'register' ) );
}
