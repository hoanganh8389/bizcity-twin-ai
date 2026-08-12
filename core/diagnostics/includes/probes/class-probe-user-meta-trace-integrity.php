<?php
/**
 * Diagnostics probe: PHASE-1.23 user-meta semantic trace integrity.
 *
 * Read-only structural audit for forensic metadata events. It never reads or
 * writes a user-meta value itself.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_User_Meta_Trace_Integrity', false ) ) {
	return;
}

final class BizCity_Probe_User_Meta_Trace_Integrity implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.user_meta.trace_integrity'; }
	public function label(): string { return 'User-meta semantic trace integrity'; }
	public function description(): string {
		return 'Audits forensic user-meta operation, scope, key family and caller evidence without exposing metadata values.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 22; }
	public function icon(): string { return 'user-round-check'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Twin_Trace' ) || ! method_exists( 'BizCity_Twin_Trace', 'runtime_entries' ) ) {
			return new WP_Error( 'user_meta_trace_api_missing', 'Runtime trace API chưa sẵn sàng.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W5 - validate redacted
		// user-meta events only; no metadata operation is triggered by this probe.
		$entries = BizCity_Twin_Trace::runtime_entries();
		$events = array();
		$invalid = array();
		$operations = array( 'user_meta_get', 'user_meta_update', 'user_meta_add', 'user_meta_delete' );
		foreach ( $entries as $entry ) {
			$data = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();
			if ( (string) ( $data['feature'] ?? '' ) !== 'user_meta' ) {
				continue;
			}
			$events[] = $data;
			if ( ! in_array( (string) ( $data['operation'] ?? '' ), $operations, true )
				|| empty( $data['user_scope_hash'] )
				|| empty( $data['meta_key_family'] )
				|| empty( $data['meta_key_hash'] )
				|| (int) ( $data['blog_id'] ?? 0 ) < 0
				|| ! isset( $data['caller_file'], $data['caller_line'] ) ) {
				$invalid[] = array(
					'event_id' => (string) ( $data['event_id'] ?? '' ),
					'operation' => (string) ( $data['operation'] ?? '' ),
				);
			}
			foreach ( array( 'meta_key', 'meta_value', 'raw_value', 'value' ) as $unsafe_key ) {
				if ( array_key_exists( $unsafe_key, $data ) ) {
					$invalid[] = array( 'event_id' => (string) ( $data['event_id'] ?? '' ), 'reason' => 'raw_meta_field' );
				}
			}
		}

		$ctx->emit_step( array(
			'label'  => 'Runtime · user-meta events are scoped and redacted',
			'status' => empty( $events ) ? 'warn' : ( empty( $invalid ) ? 'pass' : 'fail' ),
			'detail' => self::json_detail( array(
				'events'  => count( $events ),
				'invalid' => array_slice( $invalid, 0, 20 ),
			) ),
		) );

		$pass = ! empty( $events ) && empty( $invalid );
		return array(
			'status'   => $pass ? 'pass' : ( empty( $invalid ) ? 'warn' : 'fail' ),
			'summary'  => $pass
				? 'User-meta trace events contain scoped hashes, key families and caller metadata without raw values.'
				: ( empty( $events ) ? 'No user-meta trace event was observed; run a forensic request.' : 'User-meta trace contains invalid or unsafe fields.' ),
			'fix_hint' => $pass ? '' : 'Use `?bizcity_qm_probe=1` or BIZCITY_TWIN_USER_META_TRACE; never log raw metadata values.',
		);
	}

	private static function json_detail( $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public function cleanup(): void {}

	public static function register( $list ) {
		$list[] = 'BizCity_Probe_User_Meta_Trace_Integrity';
		return $list;
	}
}

if ( false === has_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_User_Meta_Trace_Integrity', 'register' ) ) ) {
	add_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_User_Meta_Trace_Integrity', 'register' ) );
}
