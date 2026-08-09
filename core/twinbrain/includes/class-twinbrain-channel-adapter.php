<?php
/**
 * Canonical inbound channel boundary for TwinBrain.
 *
 * Channel callers normalize provider payloads before entering this class. The
 * boundary owns runtime invocation, but never owns memory, goal state, or LLM
 * provider policy.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_Channel_Adapter' ) ) {
	return;
}

class BizCity_TwinBrain_Channel_Adapter {

	/**
	 * Normalize a canonical envelope and apply safe defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function normalize_envelope( $raw_payload ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G3 — normalize all channel callers at one Brain boundary.
		$payload = is_array( $raw_payload ) ? $raw_payload : array();
		$platform = strtoupper( trim( (string) ( $payload['platform'] ?? $payload['channel'] ?? '' ) ) );
		$user_id = (int) ( $payload['wp_user_id'] ?? $payload['user_id'] ?? 0 );
		$channel_class = (string) ( $payload['channel_class'] ?? '' );
		if ( $channel_class === '' && class_exists( 'BizCity_Memory_Identity_Scope' ) ) {
			$contract = BizCity_Memory_Identity_Scope::subject_contract( array_merge( $payload, array( 'platform' => $platform, 'user_id' => $user_id ) ) );
			$channel_class = (string) ( $contract['channel_class'] ?? 'unknown' );
		}
		$chat_kind = strtolower( trim( (string) ( $payload['chat_kind'] ?? '' ) ) );
		$is_group = ! empty( $payload['is_group'] ) || 'group' === $chat_kind || 'group' === strtolower( (string) ( $payload['provider_chat_type'] ?? '' ) );
		return array_merge( $payload, array(
			'platform' => $platform,
			'channel' => $platform,
			'wp_user_id' => $user_id,
			'user_id' => $user_id,
			'text' => trim( (string) ( $payload['text'] ?? $payload['prompt'] ?? $payload['message_text'] ?? '' ) ),
			'chat_id' => trim( (string) ( $payload['chat_id'] ?? '' ) ),
			'external_user_id' => trim( (string) ( $payload['external_user_id'] ?? $payload['sender_user_id'] ?? $payload['from_user_id'] ?? '' ) ),
			'channel_class' => $channel_class,
			'is_group' => $is_group,
			'identity_is_stable' => array_key_exists( 'identity_is_stable', $payload ) ? ! empty( $payload['identity_is_stable'] ) : true,
			'identity_guest_bind' => 'guest_channel' === $channel_class && ! $is_group,
		) );
	}

	/**
	 * Run one canonical Brain turn.
	 *
	 * @return array<string,mixed>
	 */
	final public function handle( $raw_payload, array $extra_opts = array() ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G3 — route every adapter turn through TwinBrain Runtime.
		$envelope = $this->normalize_envelope( $raw_payload );
		if ( $envelope['text'] === '' ) {
			return array( 'ok' => false, 'error' => 'empty_prompt' );
		}
		if ( ! empty( $envelope['is_group'] ) ) {
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — never bind group traffic to a personal Brain subject.
			return array( 'ok' => false, 'error' => 'group_identity_not_supported' );
		}
		if ( ! in_array( (string) $envelope['channel_class'], array( 'guest_channel', 'user_bound' ), true ) ) {
			return array( 'ok' => false, 'error' => 'channel_subject_unresolved' );
		}
		$runtime_opts = array_merge( BizCity_TwinBrain_Brain_Session_Resolver::build_opts( $envelope ), $extra_opts );
		if ( ! empty( $runtime_opts['_session_error'] ) ) {
			return array( 'ok' => false, 'error' => (string) $runtime_opts['_session_error'] );
		}
		$envelope['session_id'] = (string) ( $runtime_opts['session_id'] ?? '' );
		$envelope['identity_uuid'] = (string) ( $runtime_opts['identity_uuid'] ?? '' );
		if ( 'user_bound' === $envelope['channel_class'] && (int) $runtime_opts['user_id'] <= 0 ) {
			return array( 'ok' => false, 'error' => 'wp_user_id_required' );
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Runtime' ) ) {
			return array( 'ok' => false, 'error' => 'twinbrain_runtime_unavailable' );
		}
		$runtime = BizCity_TwinBrain_Runtime::instance();
		$start = $runtime->start_turn( $envelope['text'], $runtime_opts );
		if ( empty( $start['trace_id'] ) ) {
			return array( 'ok' => false, 'error' => 'twinbrain_start_failed', 'start' => $start );
		}
		// [2026-08-03 Johnny Chu] R-TGL-CS — preserve Goal/Case scope into completion and downstream Memory Writer trace.
		$completion_opts = array_merge( $runtime_opts, array(
			'guru_id' => (int) ( $start['guru_id'] ?? $runtime_opts['guru_id'] ?? 0 ),
			'tool_force' => (string) ( $start['tool_force'] ?? '' ),
			'subject_context_md' => (string) ( $start['subject_context_md'] ?? '' ),
			'subject_context_label' => (string) ( $start['subject_context_label'] ?? '' ),
			'subject_id' => (int) ( $start['subject_id'] ?? $runtime_opts['user_id'] ?? 0 ),
			'_subject_profile_resolved' => ! empty( $start['_subject_profile_resolved'] ),
			'identity_uuid' => (string) ( $start['identity_uuid'] ?? $runtime_opts['identity_uuid'] ?? '' ),
			'identity_state' => (string) ( $start['identity_state'] ?? 'unknown' ),
			'subject_contract' => (array) ( $start['subject_contract'] ?? array() ),
			'goal_loop_state' => (array) ( $start['goal_loop_state'] ?? array() ),
			'goal_loop' => (array) ( $start['goal_loop_state'] ?? array() ),
			'goal_contract' => (array) ( $start['goal_contract'] ?? array() ),
			'goal_loop_brief' => (string) ( $start['goal_loop_brief'] ?? '' ),
			'answer_depth' => (string) ( $start['answer_depth'] ?? $runtime_opts['answer_depth'] ?? 'high' ), // [2026-08-07 Johnny Chu] V4-DEPTH — preserve resolved MPR tier across channel completion.
			'goal_loop_pre_turn_completed' => ! empty( $start['goal_loop_pre_turn_completed'] ), // [2026-08-07 Johnny Chu] V4-DEPTH — prevent duplicate Goal Parser invocation.
			'memory_scope' => (string) ( $start['memory_scope'] ?? $runtime_opts['memory_scope'] ?? '' ),
			'case_id' => (string) ( $start['case_id'] ?? $runtime_opts['case_id'] ?? '' ),
			'subject_key' => (string) ( $start['subject_key'] ?? $runtime_opts['subject_key'] ?? '' ),
			'goal_id' => (string) ( $start['goal_id'] ?? $runtime_opts['goal_id'] ?? '' ),
		) );
		$done = $runtime->complete_turn( (string) $start['trace_id'], $envelope['text'], (array) ( $start['candidates'] ?? array() ), (array) ( $start['tool_candidates'] ?? array() ), $completion_opts );
		return $this->format_outbound( array_merge( $start, $done ), $envelope );
	}

	/**
	 * Keep the runtime result intact and expose a stable outbound answer field.
	 *
	 * @return array<string,mixed>
	 */
	public function format_outbound( array $brain_result, array $envelope ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G3 — expose channel-neutral answer and identity evidence.
		$synthesis = (array) ( $brain_result['synthesis'] ?? array() );
		$answer = (string) ( $synthesis['answer_md'] ?? $synthesis['answer'] ?? '' );
		if ( $answer === '' ) {
			$answer = (string) ( $brain_result['answer_md'] ?? '' );
		}
		$brain_result['answer'] = $answer;
		$brain_result['channel'] = (string) ( $envelope['platform'] ?? '' );
		$brain_result['session_id'] = (string) ( $brain_result['session_id'] ?? $envelope['session_id'] ?? '' );
		$brain_result['identity_uuid'] = (string) ( $brain_result['identity_uuid'] ?? $envelope['identity_uuid'] ?? '' );
		return $brain_result;
	}
}
