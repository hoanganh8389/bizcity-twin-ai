<?php
/**
 * BizCity Diagnostics — twinbrain.brain.mood.sampler probe (BRAIN-SESSIONS BS-4).
 *
 * Real-call smoke for the empathic mood sampler. Mints a fresh session,
 * synthesizes the cadence-3 turn boundary by emitting 3 user_message events
 * directly into the canonical event_stream, then invokes
 * BizCity_TwinBrain_Memory_Writer::sample_mood() with positive cue text and
 * verifies:
 *   • status === 'sampled' (cadence + session gating worked)
 *   • valence ≥ +0.2 with label ∈ {positive, mildly_positive}
 *   • brain_session_mood_sampled event landed in event_stream w/ session_id
 *   • Sessions_Manager::latest_mood() returns the same payload
 *   • VIEW bizcity_brain_sessions flips has_mood = 1
 *   • Memory_Recall block contains "Trạng thái cảm xúc" (Tier F render)
 *   • idempotent: 2nd invocation w/ same trace_id returns 'skipped:cached'
 *
 * Cleans up by archiving the test session (no DELETE — append-only stream).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-03 (Phase BRAIN-SESSIONS BS-4)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_TwinBrain_Mood_Sampler', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Mood_Sampler implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'twinbrain.brain.mood.sampler'; }
	public function label(): string       { return 'TwinBrain Mood Sampler (BS-4)'; }
	public function description(): string {
		return 'Synthesize cadence-3 user turns → sample_mood() → verify event emit + latest_mood() + VIEW has_mood + Recall Tier F render.';
	}
	public function severity(): string { return 'major'; }
	public function order(): int       { return 68; }
	public function icon(): string     { return 'sparkles'; }
	public function estimate_ms(): int { return 600; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Sessions_Manager' ) ) {
			return 'BizCity_TwinBrain_Sessions_Manager chưa load.';
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Memory_Writer' ) ) {
			return 'BizCity_TwinBrain_Memory_Writer chưa load.';
		}
		if ( ! method_exists( 'BizCity_TwinBrain_Memory_Writer', 'sample_mood' ) ) {
			return 'Memory_Writer thiếu sample_mood() — BS-4 chưa deploy.';
		}
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			return 'BizCity_Twin_Event_Bus chưa load.';
		}
		if ( get_current_user_id() <= 0 ) {
			return 'Probe cần admin login để mint test session.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$user_id  = get_current_user_id();
		$mgr      = BizCity_TwinBrain_Sessions_Manager::instance();
		$writer   = BizCity_TwinBrain_Memory_Writer::instance();
		$evidence = [];

		// ---- 1. Mint fresh session. ----------------------------------
		$created = $mgr->create( [ 'user_id' => $user_id, 'source' => 'system', 'title' => 'probe.bs4.mood' ] );
		$session_id = (string) ( $created['session_id'] ?? '' );
		if ( $session_id === '' ) {
			return [ 'status' => 'fail', 'error' => 'create() did not return session_id.' ];
		}
		$evidence['session_id'] = $session_id;
		$ctx->emit_step( [ 'label' => 'create() minted session', 'status' => 'pass', 'detail' => $session_id ] );

		// ---- 2. Synthesize 3 user_message events to hit cadence (3). -
		// Mood sampler gates on (turn_index >= 1 && turn_index % cadence == 0).
		// Default cadence is 3 → emit 3 turns to satisfy modulo.
		$trace_id = 'probe-bs4-' . substr( md5( (string) microtime( true ) ), 0, 8 );
		for ( $i = 1; $i <= 3; $i++ ) {
			// [2026-06-04 Johnny Chu] HOTFIX — user_message requires 'content' per taxonomy schema (not 'text').
			BizCity_Twin_Event_Bus::dispatch_v2(
				'user_message',
				[ 'content' => 'turn ' . $i, 'turn' => $i ],
				[ 'session_id' => $session_id, 'user_id' => $user_id, 'trace_id' => $trace_id . '-t' . $i ]
			);
		}
		$ctx->emit_step( [ 'label' => 'synthesized 3 user_message events', 'status' => 'pass', 'detail' => 'cadence=3' ] );

		// ---- 3. Call sample_mood() w/ positive prompt + answer. ------
		// Heuristic should score these strongly positive (multiple cues).
		$prompt = 'Cảm ơn bro, tuyệt quá! Mình thích cách giải thích này, hay quá!';
		$answer = 'Mừng quá, glad that helped — perfect timing.';
		$mood = $writer->sample_mood( [
			'trace_id'   => $trace_id,
			'session_id' => $session_id,
			'user_id'    => $user_id,
			'turn_index' => 3,
			'prompt'     => $prompt,
			'answer'     => $answer,
		] );
		$evidence['mood'] = $mood;
		$status = (string) ( $mood['status'] ?? '' );
		$valence = (float)  ( $mood['valence'] ?? 0.0 );
		$label   = (string) ( $mood['label']   ?? '' );
		$sampled_pass = ( $status === 'sampled' ) && ( $valence >= 0.2 ) && in_array( $label, [ 'positive', 'mildly_positive' ], true );
		$ctx->emit_step( [
			'label'  => 'sample_mood() returned status=sampled w/ positive valence',
			'status' => $sampled_pass ? 'pass' : 'fail',
			'detail' => sprintf( 'status=%s valence=%.2f label=%s', $status, $valence, $label ),
		] );
		if ( ! $sampled_pass ) {
			$mgr->archive( $session_id, [ 'user_id' => $user_id, 'reason' => 'probe_cleanup' ] );
			return [ 'status' => 'fail', 'error' => 'sample_mood() did not return positive sample.', 'evidence' => $evidence ];
		}

		// ---- 4. Event_stream contains brain_session_mood_sampled. ----
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_twin_event_stream';
		$evt_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl} WHERE session_id = %s AND event_type = 'brain_session_mood_sampled'",
			$session_id
		) );
		$evt_pass = $evt_count >= 1;
		$ctx->emit_step( [
			'label'  => 'event_stream has brain_session_mood_sampled row',
			'status' => $evt_pass ? 'pass' : 'fail',
			'detail' => 'count=' . $evt_count,
		] );

		// ---- 5. latest_mood() echoes the same payload. ---------------
		$latest = $mgr->latest_mood( $session_id );
		$lm_pass = is_array( $latest )
			&& abs( (float) ( $latest['valence'] ?? 0 ) - $valence ) < 0.001
			&& (string) ( $latest['label'] ?? '' ) === $label;
		$evidence['latest_mood'] = $latest;
		$ctx->emit_step( [
			'label'  => 'Sessions_Manager::latest_mood() echoes payload',
			'status' => $lm_pass ? 'pass' : 'fail',
			'detail' => is_array( $latest )
				? sprintf( 'valence=%.2f label=%s turn=%d', (float) $latest['valence'], (string) $latest['label'], (int) ( $latest['turn_index'] ?? 0 ) )
				: 'null',
		] );

		// ---- 6. VIEW has_mood = 1. -----------------------------------
		$view = BizCity_TwinBrain_Schema::sessions_view_name();
		$has_mood = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT has_mood FROM {$view} WHERE session_id = %s",
			$session_id
		) );
		$view_pass = $has_mood === 1;
		$ctx->emit_step( [
			'label'  => 'VIEW bizcity_brain_sessions.has_mood = 1',
			'status' => $view_pass ? 'pass' : 'fail',
			'detail' => 'has_mood=' . $has_mood,
		] );

		// ---- 7. Memory_Recall renders Tier F. ------------------------
		$tier_f_pass = false;
		$recall_block_excerpt = '';
		if ( class_exists( 'BizCity_TwinBrain_Memory_Recall' ) ) {
			try {
				$recall = BizCity_TwinBrain_Memory_Recall::instance()->collect(
					$user_id,
					$prompt,
					[ 'session_id' => $session_id ]
				);
				$block = (string) ( $recall['block'] ?? '' );
				$recall_block_excerpt = mb_substr( $block, 0, 200 );
				$tier_f_pass = ( mb_strpos( $block, 'Trạng thái cảm xúc' ) !== false )
					&& ( (int) ( $recall['counts']['F'] ?? 0 ) === 1 );
			} catch ( \Throwable $e ) {
				$recall_block_excerpt = 'recall_throw:' . $e->getMessage();
			}
		}
		$ctx->emit_step( [
			'label'  => 'Memory_Recall renders Tier F (cảm xúc)',
			'status' => $tier_f_pass ? 'pass' : 'fail',
			'detail' => $tier_f_pass ? 'block contains Tier F + counts.F=1' : 'no Tier F render',
		] );
		$evidence['recall_excerpt'] = $recall_block_excerpt;

		// ---- 8. Idempotency: 2nd call w/ same trace_id → cached. -----
		$mood2 = $writer->sample_mood( [
			'trace_id'   => $trace_id,
			'session_id' => $session_id,
			'user_id'    => $user_id,
			'turn_index' => 3,
			'prompt'     => $prompt,
			'answer'     => $answer,
		] );
		$idem_pass = ( (string) ( $mood2['status'] ?? '' ) === 'skipped:cached' );
		$ctx->emit_step( [
			'label'  => 'idempotent: 2nd call returns skipped:cached',
			'status' => $idem_pass ? 'pass' : 'fail',
			'detail' => 'status=' . (string) ( $mood2['status'] ?? '' ),
		] );

		// ---- 9. Cleanup. ---------------------------------------------
		$mgr->archive( $session_id, [ 'user_id' => $user_id, 'reason' => 'probe_cleanup' ] );

		$all_pass = $evt_pass && $lm_pass && $view_pass && $tier_f_pass && $idem_pass;
		if ( ! $all_pass ) {
			return [
				'status'   => 'fail',
				'error'    => 'One or more BS-4 assertions failed — see evidence.',
				'evidence' => $evidence,
			];
		}
		return [
			'status'   => 'pass',
			'message'  => sprintf( 'BS-4 mood OK · valence=%.2f label=%s · event+VIEW+Tier F+idempotent.', $valence, $label ),
			'evidence' => $evidence,
		];
	}

	// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-4 — satisfy interface contract; test event archived inside run(), no additional cleanup needed.
	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Mood_Sampler';
	return $list;
} );
