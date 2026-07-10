<?php
/**
 * BizCity CRM — Admin Chat Policy (Wave B).
 *
 * Central guard for inbound channel actions. Returns one of:
 *   allow    — execute immediately
 *   confirm  — require 2-step CONFIRM XXXX inline (Wave C)
 *   deny     — reject with reason
 *
 * Decision matrix (in order):
 *   1. No grant row OR grant revoked/pending/expired → deny
 *   2. Tool override = explicit deny → deny
 *   3. Tool override = explicit confirm → confirm
 *   4. Tool ∉ guru.skills.tools[] (R-MPRT-5 bridge) → deny (anti-jailbreak)
 *   5. Tool class = D + override != allow → confirm
 *   6. Tool class = R + over quota_per_day → deny
 *   7. Otherwise → allow
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Admin_Chat_Policy {

	const DECISION_ALLOW   = 'allow';
	const DECISION_CONFIRM = 'confirm';
	const DECISION_DENY    = 'deny';

	/**
	 * Evaluate one tool-execution intent.
	 *
	 * @param int    $user_id          WP user (resolved from chat_id via Wave A).
	 * @param int    $character_id     Guru.
	 * @param ?int   $binding_id       Channel binding row (NULL = wildcard match).
	 * @param string $tool_id          Tool registry id.
	 * @param string $tool_class       'P' | 'R' | 'D' (Producer/Retriever/Distributor).
	 * @return array{decision:string,reason:string,grant_id:?int}
	 */
	public static function evaluate( int $user_id, int $character_id, ?int $binding_id, string $tool_id, string $tool_class ): array {
		if ( $user_id <= 0 ) {
			return self::deny( 'no_user' );
		}

		$grant = BizCity_CRM_Admin_Chat_Grants::find( $user_id, $character_id, $binding_id );
		if ( ! $grant ) {
			// Try wildcard (binding_id = NULL) before giving up.
			$grant = $binding_id ? BizCity_CRM_Admin_Chat_Grants::find( $user_id, $character_id, null ) : null;
		}
		if ( ! $grant ) {
			return self::deny( 'no_grant' );
		}
		if ( $grant['status'] !== BizCity_CRM_Admin_Chat_Grants::STATUS_ACTIVE ) {
			return self::deny( 'grant_' . $grant['status'] );
		}
		if ( ! empty( $grant['expires_at'] ) && strtotime( $grant['expires_at'] . ' UTC' ) < time() ) {
			return self::deny( 'grant_expired' );
		}

		// Per-tool override wins over class-level toggles.
		$overrides = ! empty( $grant['tool_overrides_json'] )
			? (array) json_decode( $grant['tool_overrides_json'], true )
			: array();
		$override = $overrides[ $tool_id ] ?? null;
		if ( $override === 'deny' )    { return self::deny( 'tool_override_deny', $grant['id'] ); }
		if ( $override === 'confirm' ) { return self::confirm( 'tool_override_confirm', $grant['id'] ); }

		// Anti-jailbreak: tool must be bound to the guru via R-MPRT-5 bridge.
		if ( class_exists( 'BizCity_Guru_Skill_Bridge' )
			&& method_exists( 'BizCity_Guru_Skill_Bridge', 'tools_for_guru' )
		) {
			$allowed_tools = (array) BizCity_Guru_Skill_Bridge::tools_for_guru( $character_id );
			if ( $allowed_tools && ! in_array( $tool_id, $allowed_tools, true ) ) {
				return self::deny( 'tool_not_in_guru', $grant['id'] );
			}
		}

		// Class-level rules.
		switch ( strtoupper( $tool_class ) ) {
			case 'P':
				if ( empty( $grant['allow_producer'] ) ) { return self::deny( 'producer_disabled', $grant['id'] ); }
				break;
			case 'R':
				if ( empty( $grant['allow_retriever'] ) ) { return self::deny( 'retriever_disabled', $grant['id'] ); }
				if ( (int) $grant['quota_used_today'] >= (int) $grant['quota_per_day']
					&& strtotime( $grant['quota_reset_at'] . ' UTC' ) > time()
				) {
					return self::deny( 'quota_exceeded', $grant['id'] );
				}
				break;
			case 'D':
				if ( empty( $grant['allow_distributor'] ) ) { return self::deny( 'distributor_disabled', $grant['id'] ); }
				if ( $override !== 'allow' ) {
					return self::confirm( 'distributor_default_confirm', $grant['id'] );
				}
				break;
			default:
				return self::deny( 'unknown_tool_class', $grant['id'] );
		}

		return array( 'decision' => self::DECISION_ALLOW, 'reason' => 'ok', 'grant_id' => (int) $grant['id'] );
	}

	private static function deny( string $reason, ?int $grant_id = null ): array {
		return array( 'decision' => self::DECISION_DENY, 'reason' => $reason, 'grant_id' => $grant_id );
	}

	private static function confirm( string $reason, ?int $grant_id = null ): array {
		return array( 'decision' => self::DECISION_CONFIRM, 'reason' => $reason, 'grant_id' => $grant_id );
	}
}
