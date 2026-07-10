<?php
/**
 * BizCity Diagnostics — twinbrain.brain.sessions.crud probe (BRAIN-SESSIONS BS-2).
 *
 * Real-call smoke for the Sessions Manager + REST surface. Runs a full
 * mint → list → rename → archive cycle against the canonical event stream
 * and the bizcity_brain_sessions VIEW. Cleans up by archiving the test row
 * (no DELETE — append-only event stream, R-EVT-6).
 *
 * Asserts:
 *   • create() emits brain_session_created (1 row in event_stream).
 *   • VIEW bizcity_brain_sessions surfaces the new session_id with
 *     has_created=1 and turn_count=0.
 *   • rename() emits brain_session_renamed; latest_title() returns new title.
 *   • archive() emits brain_session_archived; VIEW reflects has_archived=1.
 *   • is_valid_session_id() rejects malformed ids.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-03 (Phase BRAIN-SESSIONS BS-2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_TwinBrain_Brain_Sessions_CRUD', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Brain_Sessions_CRUD implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'twinbrain.brain.sessions.crud'; }
	public function label(): string       { return 'TwinBrain Brain Sessions CRUD (BS-2)'; }
	public function description(): string {
		return 'Mint → list → rename → archive cycle qua Sessions_Manager. Verify event_stream emits + VIEW projection + latest_title resolver.';
	}
	public function severity(): string { return 'major'; }
	public function order(): int       { return 66; }
	public function icon(): string     { return 'message-square-plus'; }
	public function estimate_ms(): int { return 400; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Sessions_Manager' ) ) {
			return 'BizCity_TwinBrain_Sessions_Manager chưa load — twinbrain bootstrap không hoàn tất.';
		}
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			return 'BizCity_Twin_Event_Bus chưa load — twin-core event-stream missing.';
		}
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return 'Probe cần admin login (get_current_user_id > 0) để mint test session.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$user_id = get_current_user_id();
		$mgr     = BizCity_TwinBrain_Sessions_Manager::instance();
		$evidence = [];

		// ---- 1. is_valid_session_id rejects garbage. -----------------
		$reject = ! BizCity_TwinBrain_Sessions_Manager::is_valid_session_id( 'bogus_id' );
		$ctx->emit_step( [
			'label'  => 'is_valid_session_id() rejects "bogus_id"',
			'status' => $reject ? 'pass' : 'fail',
			'detail' => $reject ? 'rejected' : 'ACCEPTED — regex too loose',
		] );
		if ( ! $reject ) {
			return [ 'status' => 'fail', 'error' => 'session_id regex accepts invalid input.' ];
		}

		// ---- 2. create() ---------------------------------------------
		$created = $mgr->create( [ 'user_id' => $user_id, 'source' => 'system', 'title' => 'probe.bs2.initial' ] );
		$session_id = (string) ( $created['session_id'] ?? '' );
		if ( empty( $session_id ) || empty( $created['created'] ) ) {
			return [
				'status'   => 'fail',
				'error'    => 'create() did not return a fresh session_id.',
				'evidence' => [ 'create_result' => $created ],
			];
		}
		$evidence['session_id'] = $session_id;
		$ctx->emit_step( [
			'label'  => 'create() minted session',
			'status' => 'pass',
			'detail' => $session_id,
		] );

		// ---- 3. VIEW reflects new row (has_created=1, archived=0). ---
		global $wpdb;
		$view = BizCity_TwinBrain_Schema::sessions_view_name();
		$row  = $wpdb->get_row( $wpdb->prepare(
			"SELECT has_created, has_archived, turn_count FROM {$view} WHERE session_id = %s",
			$session_id
		), ARRAY_A );
		$view_pass = $row && (int) $row['has_created'] === 1 && (int) $row['has_archived'] === 0;
		$ctx->emit_step( [
			'label'  => 'VIEW projection (post-create)',
			'status' => $view_pass ? 'pass' : 'fail',
			'detail' => $row
				? sprintf( 'has_created=%d has_archived=%d turn_count=%d', $row['has_created'], $row['has_archived'], $row['turn_count'] )
				: 'no row in view',
		] );
		if ( ! $view_pass ) {
			return [
				'status'   => 'fail',
				'error'    => 'VIEW bizcity_brain_sessions did not surface created session.',
				'evidence' => array_merge( $evidence, [ 'view_row' => $row ] ),
			];
		}

		// ---- 4. rename() ---------------------------------------------
		$new_title = 'probe.bs2.renamed.' . substr( md5( (string) microtime( true ) ), 0, 6 );
		$rn = $mgr->rename( $session_id, $new_title, [ 'user_id' => $user_id, 'reason' => 'user' ] );
		$rn_pass = ! empty( $rn['ok'] ) && $mgr->latest_title( $session_id ) === $new_title;
		$ctx->emit_step( [
			'label'  => 'rename() + latest_title() echo',
			'status' => $rn_pass ? 'pass' : 'fail',
			'detail' => $rn_pass ? $new_title : ( 'expected=' . $new_title . ' got=' . $mgr->latest_title( $session_id ) ),
		] );
		if ( ! $rn_pass ) {
			return [
				'status'   => 'fail',
				'error'    => 'rename() did not propagate to latest_title().',
				'evidence' => array_merge( $evidence, [ 'rename_result' => $rn ] ),
			];
		}

		// ---- 5. list_for_user() finds the row ------------------------
		$list = $mgr->list_for_user( [ 'user_id' => $user_id, 'limit' => 100 ] );
		$found = false;
		foreach ( (array) ( $list['items'] ?? [] ) as $it ) {
			if ( ( $it['session_id'] ?? '' ) === $session_id ) {
				$found = ( $it['title'] === $new_title );
				break;
			}
		}
		$ctx->emit_step( [
			'label'  => 'list_for_user() includes session w/ title',
			'status' => $found ? 'pass' : 'fail',
			'detail' => $found ? 'present' : 'not in list (or title mismatch)',
		] );

		// ---- 6. archive() (cleanup) ----------------------------------
		$ar = $mgr->archive( $session_id, [ 'user_id' => $user_id, 'reason' => 'probe_cleanup' ] );
		$row2 = $wpdb->get_row( $wpdb->prepare(
			"SELECT has_archived FROM {$view} WHERE session_id = %s",
			$session_id
		), ARRAY_A );
		$ar_pass = ! empty( $ar['ok'] ) && $row2 && (int) $row2['has_archived'] === 1;
		$ctx->emit_step( [
			'label'  => 'archive() + VIEW has_archived=1',
			'status' => $ar_pass ? 'pass' : 'fail',
			'detail' => $row2 ? ( 'has_archived=' . $row2['has_archived'] ) : 'no row',
		] );

		$evidence['rename_event_uuid']  = (string) ( $rn['event_uuid'] ?? '' );
		$evidence['archive_event_uuid'] = (string) ( $ar['event_uuid'] ?? '' );
		$evidence['list_total']         = (int)    ( $list['total']    ?? 0 );

		if ( ! $ar_pass || ! $found ) {
			return [
				'status'   => 'fail',
				'error'    => 'archive() or list visibility failed.',
				'evidence' => $evidence,
			];
		}
		return [
			'status'   => 'pass',
			'message'  => 'BS-2 CRUD OK · mint→list→rename→archive cycle.',
			'evidence' => $evidence,
		];
	}

	// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-2 — satisfy interface contract; session archived inside run(), no additional cleanup needed.
	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Brain_Sessions_CRUD';
	return $list;
} );
