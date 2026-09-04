<?php
/**
 * Server-authorized Context Bank retrieval scope baseline.
 *
 * This resolver emits policy metadata only. It does not query storage or
 * replace the canonical Vertical Bridge, notebook or entitlement owners.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Scope_Resolver', false ) ) {
	return;
}

final class BizCity_Context_Bank_Scope_Resolver {

	const VERSION = '1.0.0';

	/**
	 * Resolve a bounded server-owned scope for a Context Bank consumer.
	 *
	 * @param array<string,mixed> $request_context Untrusted request hints.
	 * @return array<string,mixed>
	 */
	public static function resolve( array $request_context = array() ) {
		// [2026-09-01 Johnny Chu] PHASE-CB7.1 — derive identity, tenant and private-memory eligibility from server state before accepting retrieval hints.
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$group = (string) ( $request_context['chat_kind'] ?? '' ) === 'group' || strtoupper( (string) ( $request_context['provider_chat_type'] ?? '' ) ) === 'GROUP';
		$channel = sanitize_key( (string) ( $request_context['channel'] ?? $request_context['platform'] ?? '' ) );
		$user_bound = in_array( strtoupper( $channel ), array( 'ZALO_BOT', 'TELEGRAM', 'TWINCHAT', 'TWINCHAT_BE', 'TWIN_GPT' ), true );
		if ( $blog_id <= 0 ) {
			return self::skip( 'tenant_context_missing' );
		}
		if ( $user_bound && $user_id <= 0 ) {
			return self::skip( 'linked_user_required' );
		}
		if ( $group ) {
			return array( 'ok' => true, 'effective_mode' => 'skip', 'reason_bucket' => 'group_private_scope_denied', 'blog_id' => $blog_id, 'owner_user_id' => 0, 'allowed_contracts' => array(), 'allowed_record_kinds' => array(), 'budgets' => self::budgets(), 'policy_version' => self::VERSION );
		}
		if ( $user_id <= 0 ) {
			return self::skip( 'identity_required' );
		}
		$requested_mode = sanitize_key( (string) ( $request_context['mode'] ?? $request_context['scope'] ?? 'recent_identity' ) );
		if ( ! in_array( $requested_mode, array( 'vertical', 'notebook', 'hybrid', 'recent_identity' ), true ) ) {
			$requested_mode = 'recent_identity';
		}
		$policy = self::resolve_retrieval_policy( $requested_mode, $request_context, $user_id );
		if ( empty( $policy['ok'] ) ) {
			return self::skip( (string) ( $policy['reason_bucket'] ?? 'retrieval_scope_invalid' ) );
		}
		return array(
			'ok' => true,
			'effective_mode' => $requested_mode,
			'reason_bucket' => '',
			'blog_id' => $blog_id,
			'owner_user_id' => $user_id,
			// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB7.2 — authorize every admitted W4 reference family through one server-owned scope allowlist.
			'allowed_contracts' => array( 'core.knowledge.user_memory', 'core.intent.episodic_memory', 'core.intent.rolling_memory', 'modules.webchat.session_memory', 'modules.twinchat.memory_notes', 'core.twin_core.context_bank_event', 'core.channel_gateway.context_corpus', 'core.context_bank.commerce_order', 'core.context_bank.rollup', 'core.skills.rule_reference' ),
			'allowed_record_kinds' => array( 'memory', 'event', 'rollup', 'rule', 'relation' ),
			'notebook_id' => (int) ( $policy['notebook_id'] ?? 0 ),
			'vertical_id' => (string) ( $policy['vertical_id'] ?? '' ),
			'policy_contracts' => (array) ( $policy['allowed_contracts'] ?? array() ),
			'budgets' => self::budgets(),
			'policy_version' => self::VERSION,
		);
	}

	private static function resolve_retrieval_policy( $mode, array $request_context, $user_id ) {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB7.1 — validate vertical and notebook hints through canonical owners before Context Bank search.
		$vertical_id = sanitize_key( (string) ( $request_context['vertical_id'] ?? '' ) );
		$notebook_id = absint( $request_context['notebook_id'] ?? 0 );
		if ( in_array( $mode, array( 'vertical', 'hybrid' ), true ) && $vertical_id !== '' ) {
			if ( ! class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' ) || ! BizCity_TwinBrain_Vertical_Bridge_Registry::get( $vertical_id ) ) {
				return array( 'ok' => false, 'reason_bucket' => 'vertical_not_registered' );
			}
		}
		if ( in_array( $mode, array( 'notebook', 'hybrid' ), true ) && $notebook_id > 0 ) {
			if ( ! class_exists( 'BizCity_TwinBrain_Notebook_Selector' ) ) {
				return array( 'ok' => false, 'reason_bucket' => 'notebook_owner_unavailable' );
			}
			$owned = BizCity_TwinBrain_Notebook_Selector::instance()->select( '', (int) $user_id, 1, array( 'force_ids' => array( $notebook_id ) ) );
			if ( empty( $owned[0] ) || (int) ( $owned[0]['notebook_id'] ?? 0 ) !== $notebook_id ) {
				return array( 'ok' => false, 'reason_bucket' => 'notebook_owner_scope_denied' );
			}
		}
		return array( 'ok' => true, 'notebook_id' => $notebook_id, 'vertical_id' => $vertical_id, 'allowed_contracts' => self::contracts_for_mode( $mode ) );
	}

	private static function contracts_for_mode( $mode ) {
		$memory = array( 'core.knowledge.user_memory', 'core.intent.episodic_memory', 'core.intent.rolling_memory', 'modules.webchat.session_memory', 'modules.twinchat.memory_notes' );
		$business = array( 'core.channel_gateway.context_corpus', 'core.context_bank.commerce_order', 'core.context_bank.rollup', 'core.skills.rule_reference' );
		if ( $mode === 'vertical' ) {
			return array_values( array_merge( $business, array( 'core.twin_core.context_bank_event' ) ) );
		}
		if ( $mode === 'notebook' ) {
			return $memory;
		}
		return array_values( array_merge( $memory, $business, array( 'core.twin_core.context_bank_event' ) ) );
	}

	private static function budgets() {
		// [2026-09-01 Johnny Chu] PHASE-CB7.1 — keep consumer budgets bounded until the CB0.3 performance matrix is deployed.
		return array( 'max_rows' => 50, 'max_pointer_follows' => 10, 'max_decrypted_bytes' => 262144, 'max_time_ms' => 250 );
	}

	private static function skip( $reason ) {
		return array( 'ok' => true, 'effective_mode' => 'skip', 'reason_bucket' => sanitize_key( (string) $reason ), 'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0, 'owner_user_id' => 0, 'allowed_contracts' => array(), 'allowed_record_kinds' => array(), 'budgets' => self::budgets(), 'policy_version' => self::VERSION );
	}
}