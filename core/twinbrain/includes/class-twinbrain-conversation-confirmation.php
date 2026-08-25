<?php
/**
 * TwinBrain Conversation Router confirmation state.
 *
 * Reuses Automation pending state when loaded and falls back to a transient
 * with the same TTL when the Automation module is not available.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-01
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Conversation_Confirmation {

	const INTENT = 'conversation_router_confirm';
	const TTL    = 600;

	public static function begin( string $key, string $prompt, array $decision ): bool {
		// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — persist the original prompt and route decision for confirmation.
		if ( $key === '' || $prompt === '' ) {
			return false;
		}
		$payload = array(
			'intent'      => self::INTENT,
			'offer_type'  => (string) ( $decision['offer_type'] ?? '' ),
			'workflow_id' => 0,
			'slots'       => array(
				'route_decision' => $decision,
				'original_prompt' => $prompt,
			),
		);
		if ( class_exists( 'BizCity_Automation_Pending_State' ) ) {
			return (bool) BizCity_Automation_Pending_State::set( $key, $payload, self::TTL );
		}
		return (bool) set_transient( self::transient_key( $key ), $payload, self::TTL );
	}

	/**
	 * @return array{status:string,prompt?:string,decision?:array}
	 */
	public static function consume( string $key, string $reply ): array {
		// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — resolve yes/no before specialized Brain execution.
		$pending = self::get( $key );
		if ( empty( $pending ) || ( $pending['intent'] ?? '' ) !== self::INTENT ) {
			return array( 'status' => 'none' );
		}
		$answer = self::normalize_reply( $reply );
		$yes = (bool) preg_match( '/^(co|u|ok|oke|yes|duoc|dung)(?:\s|$)/u', $answer );
		$no  = (bool) preg_match( '/^(khong|ko|k|thoi|no|bo qua)(?:\s|$)/u', $answer );
		if ( ! $yes && ! $no ) {
			return array( 'status' => 'invalid' );
		}
		self::clear( $key );
		$decision = (array) ( $pending['slots']['route_decision'] ?? array() );
		if ( $no ) {
			$decision = array(
				'offer_type' => (string) ( $pending['offer_type'] ?? '' ),
				'route'      => 'casual',
				'reason'     => (string) ( $pending['offer_type'] ?? '' ) === 'deep_research' ? 'deep_research_declined' : 'confirmed_generic',
				'web_mode'   => 'off',
				'companion_mode' => true,
				'confidence' => 1.0,
			);
		} else {
			// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — a positive confirmation unlocks the stored specialized route exactly once.
			$decision['needs_confirm'] = false;
			if ( ( $decision['route'] ?? '' ) === 'notebook' && empty( $decision['force_notebooks'] ) ) {
				$candidates = array_values( array_filter( array_map( 'intval', (array) ( $decision['candidate_notebook_ids'] ?? array() ) ) ) );
				if ( ! empty( $candidates ) ) {
					$decision['force_notebooks'] = array( $candidates[0] );
				}
			}
		}
		return array(
			'status'   => 'confirmed',
			'prompt'   => (string) ( $pending['slots']['original_prompt'] ?? '' ),
			'decision' => $decision,
		);
	}

	public static function is_deep_research_offer( string $key ): bool {
		// [2026-08-23 Johnny Chu] TBR-EVIDENCE-FALLBACK — distinguish post-search Deep Research offers from disabled specialized pre-routing state.
		$pending = self::get( $key );
		return ! empty( $pending ) && (string) ( $pending['intent'] ?? '' ) === self::INTENT
			&& (string) ( $pending['offer_type'] ?? '' ) === 'deep_research';
	}

	public static function clear( string $key ): void {
		// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — remove consumed or expired confirmation state.
		if ( $key === '' ) {
			return;
		}
		if ( class_exists( 'BizCity_Automation_Pending_State' ) ) {
			BizCity_Automation_Pending_State::clear( $key );
			return;
		}
		delete_transient( self::transient_key( $key ) );
	}

	public static function dispatch_prompt( array $payload, array $opts = array() ): void {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G1 — persist confirmation prompts through Event Bus v2.
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) || ! method_exists( 'BizCity_Twin_Event_Bus', 'dispatch_v2' ) ) {
			return;
		}
		try {
			BizCity_Twin_Event_Bus::dispatch_v2( 'conversation_confirm_prompt', array(
				'trace_id'   => (string) ( $payload['trace_id'] ?? '' ),
				'route'      => sanitize_key( (string) ( $payload['route'] ?? 'notebook' ) ),
				'expires_in' => max( 60, (int) ( $payload['expires_in'] ?? self::TTL ) ),
				'message'    => sanitize_text_field( (string) ( $payload['message'] ?? '' ) ),
			), array(
				'event_source' => (string) ( $opts['event_source'] ?? 'twinbrain' ),
				'trace_id'     => (string) ( $payload['trace_id'] ?? '' ),
				'session_id'   => (string) ( $opts['session_id'] ?? '' ),
				'user_id'      => (int) ( $opts['user_id'] ?? 0 ),
				'blog_id'      => (int) ( $opts['blog_id'] ?? get_current_blog_id() ),
			) );
		} catch ( \Throwable $e ) {
			error_log( '[TwinBrain][goal-loop] confirmation event skipped: ' . get_class( $e ) . ' ' . $e->getMessage() );
		}
	}

	private static function get( string $key ): array {
		// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — read Automation pending state with standalone transient fallback.
		if ( $key === '' ) {
			return array();
		}
		if ( class_exists( 'BizCity_Automation_Pending_State' ) ) {
			return (array) BizCity_Automation_Pending_State::get( $key );
		}
		$pending = get_transient( self::transient_key( $key ) );
		return is_array( $pending ) ? $pending : array();
	}

	private static function transient_key( string $key ): string {
		// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — hash session identity before transient storage.
		return 'bizcity_twinbrain_confirm_' . md5( $key );
	}

	private static function normalize_reply( string $reply ): string {
		// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — accept bounded natural-language confirmation without broad intent matching.
		$answer = strtolower( remove_accents( trim( $reply ) ) );
		$answer = preg_replace( '/[^a-z0-9\s]+/u', ' ', $answer );
		return trim( preg_replace( '/\s+/', ' ', (string) $answer ) );
	}
}
