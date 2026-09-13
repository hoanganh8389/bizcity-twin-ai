<?php
/**
 * Server-owned Context Bank retrieval mode policy.
 *
 * This registry declares bounded mode metadata only. It does not query storage,
 * resolve entitlements or execute retrieval.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Mode_Policy', false ) ) {
	return;
}

final class BizCity_Context_Bank_Mode_Policy {

	const VERSION = '1.0.0';
	const MODE_ID = 'context_bank';

	/**
	 * Return the immutable policy metadata for Context Bank Brain Mode.
	 *
	 * @return array<string,mixed>
	 */
	public static function describe() {
		// [2026-09-13 Johnny Chu - Chu Hoàng Anh] PHASE-1.33B-B1 — declare the server-owned horizontal Context Bank mode and its bounded contract allowlist.
		return array(
			'mode_id' => self::MODE_ID,
			'label' => 'Context Bank Brain',
			'owner' => 'core/context-bank + core/twinbrain',
			'scope_kind' => 'horizontal_context',
			'requires_identity' => true,
			'requires_tenant' => true,
			'group_private_scope' => 'deny',
			'requires_entitlement' => true,
			'minimum_plan' => 'free',
			'memory_policy' => 'explicit_opt_in',
			'kg_policy' => 'rollup_only',
			'feature_flag' => 'bizcity_context_bank_mpr_enabled',
			'allowed_contracts' => array(
				'core.channel_gateway.context_corpus',
				'core.context_bank.commerce_order',
				'core.context_bank.rollup',
				'core.twin_core.context_bank_event',
			),
			'policy_version' => self::VERSION,
		);
	}

	/**
	 * Return whether one mode ID is registered by this owner.
	 *
	 * @param string $mode_id Mode identifier.
	 * @return bool
	 */
	public static function has( $mode_id ) {
		return self::MODE_ID === sanitize_key( (string) $mode_id );
	}

	/**
	 * Return the server-owned contract allowlist for one mode.
	 *
	 * @param string $mode_id Mode identifier.
	 * @return array<int,string>
	 */
	public static function contracts_for( $mode_id ) {
		if ( ! self::has( $mode_id ) ) {
			return array();
		}
		$policy = self::describe();
		return (array) ( $policy['allowed_contracts'] ?? array() );
	}
}
