<?php
/**
 * BizCity Diagnostics — twinbrain.brain.sessions probe (BRAIN-SESSIONS BS-1).
 *
 * Read-only smoke for the Brain Sessions foundation. Verifies:
 *   1. Disk:    schema class + 5 event-schema JSON files present.
 *   2. Loader:  5 brain_session_* event_types registered in taxonomy v6.
 *   3. Runtime: VIEW {prefix}bizcity_brain_sessions exists and is queryable.
 *
 * No real LLM calls; safe to run repeatedly. NO write to event stream
 * (we don't dispatch a synthetic brain_session_created here — owner of the
 * Wave BS-2 runtime patch will add a separate real-call probe once the
 * REST POST /sessions endpoint ships).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-03 (Phase BRAIN-SESSIONS BS-1)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_TwinBrain_Brain_Sessions', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Brain_Sessions implements BizCity_Diagnostics_Probe {

	const REQUIRED_EVENT_TYPES = [
		'brain_session_created',
		'brain_session_renamed',
		'brain_session_archived',
		'brain_session_mood_sampled',
		'brain_session_carry_forward',
	];

	const REQUIRED_SCHEMA_FILES = [
		'brain_session_created.json',
		'brain_session_renamed.json',
		'brain_session_archived.json',
		'brain_session_mood_sampled.json',
		'brain_session_carry_forward.json',
	];

	public function id(): string          { return 'twinbrain.brain.sessions'; }
	public function label(): string       { return 'TwinBrain Brain Sessions (BS-1)'; }
	public function description(): string {
		return 'Verify foundation Wave BS-1: VIEW bizcity_brain_sessions tồn tại + 5 event_types brain_session_* registered + 5 schema JSON files trên disk.';
	}
	public function severity(): string { return 'major'; }
	public function order(): int       { return 65; }
	public function icon(): string     { return 'message-square-text'; }
	public function estimate_ms(): int { return 60; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Schema' ) ) {
			return 'BizCity_TwinBrain_Schema chưa load — twinbrain bootstrap không hoàn tất.';
		}
		if ( ! class_exists( 'BizCity_Twin_Event_Taxonomy' ) ) {
			return 'BizCity_Twin_Event_Taxonomy chưa load — twin-core event-stream missing.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$evidence = [];

		// ---- Layer 1: Disk — 5 JSON schema files. -------------------
		$schema_dir = defined( 'BIZCITY_TWINBRAIN_DIR' )
			? BIZCITY_TWINBRAIN_DIR . 'includes/event-schemas/'
			: dirname( __DIR__, 3 ) . '/twinbrain/includes/event-schemas/';

		$missing_files = [];
		foreach ( self::REQUIRED_SCHEMA_FILES as $f ) {
			if ( ! file_exists( $schema_dir . $f ) ) {
				$missing_files[] = $f;
			}
		}
		$evidence['schema_dir']    = $schema_dir;
		$evidence['missing_files'] = $missing_files;
		$ctx->emit_step( [
			'label'  => 'Schema JSON files (5)',
			'status' => empty( $missing_files ) ? 'pass' : 'fail',
			'detail' => empty( $missing_files )
				? '5/5 present'
				: 'missing: ' . implode( ', ', $missing_files ),
		] );

		// ---- Layer 2: Loader — taxonomy v6 + 5 event_types. ----------
		$tax_version = defined( 'BizCity_Twin_Event_Taxonomy::TAXONOMY_VERSION' )
			? BizCity_Twin_Event_Taxonomy::TAXONOMY_VERSION
			: 0;
		$all_types        = BizCity_Twin_Event_Taxonomy::all();
		$missing_types    = array_values( array_diff( self::REQUIRED_EVENT_TYPES, $all_types ) );
		$required_fields  = BizCity_Twin_Event_Taxonomy::required_fields();
		$evidence['taxonomy_version'] = $tax_version;
		$evidence['missing_types']    = $missing_types;
		$ctx->emit_step( [
			'label'  => 'Taxonomy event_types (5)',
			'status' => empty( $missing_types ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'taxonomy v=%d, %d/5 registered%s',
				$tax_version,
				5 - count( $missing_types ),
				$missing_types ? ' · missing: ' . implode( ', ', $missing_types ) : ''
			),
		] );

		// ---- Layer 3: Runtime — VIEW bizcity_brain_sessions queryable.
		global $wpdb;
		$view = BizCity_TwinBrain_Schema::sessions_view_name();
		$prev = $wpdb->suppress_errors( true );
		$exists_row = bizcity_tbl_exists( $view ) ? $view : null; // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		$view_exists = ( $exists_row === $view );
		$row_count   = null;
		if ( $view_exists ) {
			$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$view}" );
		}
		$wpdb->suppress_errors( $prev );
		$evidence['view_name']   = $view;
		$evidence['view_exists'] = $view_exists;
		$evidence['view_rows']   = $row_count;
		$evidence['view_version_option'] = get_option( BizCity_TwinBrain_Schema::SESSIONS_VIEW_VERSION_OPTION, '' );

		$ctx->emit_step( [
			'label'  => 'VIEW bizcity_brain_sessions',
			'status' => $view_exists ? 'pass' : 'fail',
			'detail' => $view_exists
				? sprintf( '%s · %d rows · ver=%s', $view, $row_count, $evidence['view_version_option'] )
				: $view . ' missing — ensure_sessions_view() chưa chạy hoặc event_stream table absent.',
		] );

		// ---- Aggregate ---------------------------------------------------
		$failures = [];
		if ( $missing_files )  { $failures[] = 'schema_files'; }
		if ( $missing_types )  { $failures[] = 'event_types'; }
		if ( ! $view_exists )  { $failures[] = 'view_missing'; }

		if ( empty( $failures ) ) {
			return [
				'status'   => 'pass',
				'message'  => 'BS-1 foundation OK · 5 schemas + 5 event_types + VIEW.',
				'evidence' => $evidence,
			];
		}

		$fix = [];
		if ( in_array( 'schema_files', $failures, true ) ) {
			$fix[] = 'Re-deploy core/twinbrain/includes/event-schemas/brain_session_*.json (5 files).';
		}
		if ( in_array( 'event_types', $failures, true ) ) {
			$fix[] = 'Bump TAXONOMY_VERSION ≥ 6 và confirm 5 const BRAIN_SESSION_* + required_fields() entries.';
		}
		if ( in_array( 'view_missing', $failures, true ) ) {
			$fix[] = 'Run BizCity_TwinBrain_Schema::ensure_sessions_view() (auto via init@20). Check option ' . BizCity_TwinBrain_Schema::SESSIONS_VIEW_VERSION_OPTION . '.';
		}
		return [
			'status'   => 'fail',
			'error'    => 'BS-1 failures: ' . implode( ', ', $failures ),
			'fix_hint' => implode( ' · ', $fix ),
			'evidence' => $evidence,
		];
	}

	// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-1 — satisfy interface contract; probe is read-only, no artifacts to clean.
	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Brain_Sessions';
	return $list;
} );
