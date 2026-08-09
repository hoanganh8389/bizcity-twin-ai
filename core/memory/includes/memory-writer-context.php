<?php
/**
 * Canonical channel context normalizer for TwinBrain memory writes.
 *
 * This helper does not resolve or persist identity. It only maps channel
 * payload aliases to the context contract consumed by Memory_Writer and
 * BizCity_Memory_Identity_Scope.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Memory
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! function_exists( 'bizcity_memory_writer_ctx_from_channel' ) ) {
	/**
	 * Normalize a channel payload before calling Memory_Writer.
	 *
	 * @param array $payload Inbound channel context.
	 * @return array Canonical writer context.
	 */
	function bizcity_memory_writer_ctx_from_channel( array $payload = array() ): array {
		// [2026-07-31 Johnny Chu] PHASE-1.22-MEMORY-UNIFY — normalize channel aliases before canonical identity resolution.
		$user_id = (int) ( $payload['wp_user_id'] ?? $payload['user_id'] ?? $payload['subject_id'] ?? 0 );
		$goal_loop = (array) ( $payload['goal_loop'] ?? array() );
		if ( class_exists( 'BizCity_TwinBrain_Goal_Loop_State' ) && ! empty( $goal_loop ) ) {
			$goal_loop = BizCity_TwinBrain_Goal_Loop_State::normalize( $goal_loop );
		}
		$subject_contract = class_exists( 'BizCity_Memory_Identity_Scope' )
			? BizCity_Memory_Identity_Scope::subject_contract( $payload )
			: array();
		$context = array(
			'blog_id'            => (int) ( $payload['blog_id'] ?? get_current_blog_id() ),
			'user_id'            => $user_id,
			'wp_user_id'         => $user_id,
			'subject_id'         => $user_id,
			'session_id'         => trim( (string) ( $payload['session_id'] ?? '' ) ),
			'identity_uuid'      => strtolower( trim( (string) ( $payload['identity_uuid'] ?? '' ) ) ),
			'identity_is_stable' => ! empty( $payload['identity_is_stable'] ),
			'platform'           => sanitize_key( (string) ( $payload['platform'] ?? $payload['channel'] ?? '' ) ),
			'channel'            => sanitize_key( (string) ( $payload['channel'] ?? $payload['platform'] ?? '' ) ),
			'account_id'         => sanitize_text_field( (string) ( $payload['account_id'] ?? $payload['bot_id'] ?? '' ) ),
			'external_user_id'   => sanitize_text_field( (string) ( $payload['external_user_id'] ?? $payload['from_user_id'] ?? $payload['sender_user_id'] ?? '' ) ),
			'chat_id'            => sanitize_text_field( (string) ( $payload['chat_id'] ?? $payload['conversation_chat_id'] ?? '' ) ),
			'identity_guest_bind'=> ! empty( $payload['identity_guest_bind'] ),
			'channel_class'      => (string) ( $subject_contract['channel_class'] ?? 'unknown' ),
			'subject_kind'       => (string) ( $subject_contract['subject_kind'] ?? 'unresolved' ),
			'subject_source'     => (string) ( $subject_contract['subject_source'] ?? 'none' ),
			'wp_user_required'   => ! empty( $subject_contract['wp_user_required'] ),
			'identity_temporary' => ! empty( $subject_contract['identity_temporary'] ),
			'goal_loop'          => $goal_loop,
			'notebook_id'        => (int) ( $payload['notebook_id'] ?? 0 ),
		);

		return apply_filters( 'bizcity_memory_writer_channel_context', $context, $payload );
	}
}
