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

	const VERSION = '1.1.0';
	const MODE_ID = 'context_bank';

	/**
	 * Canonical Context Bank MPR feature flag option name.
	 *
	 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — the flag is
	 * default-ON: Context Bank is part of the horizontal brain (R-BRAIN-UNIFY),
	 * not an opt-in experiment. A site must explicitly set the option to `0`
	 * (or use the `bizcity_twinbrain_context_bank_enabled` filter) to turn the
	 * layer off. Consumers must read the default from
	 * `BizCity_Context_Bank_Mode_Policy::is_enabled_default()` instead of
	 * hardcoding `false`, otherwise the layer silently stops running.
	 */
	const FEATURE_FLAG = 'bizcity_context_bank_mpr_enabled';

	/**
	 * Return the canonical default for the Context Bank MPR flag.
	 *
	 * @return bool
	 */
	public static function is_enabled_default() {
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — one owner for the default so resolver and source layer cannot drift apart. Guarded for early CLI/probe contexts where hooks are not loaded yet.
		if ( ! function_exists( 'apply_filters' ) ) {
			return true;
		}
		return (bool) apply_filters( 'bizcity_context_bank_mpr_enabled_default', true );
	}

	/**
	 * Resolve whether Context Bank MPR is enabled for the current request.
	 *
	 * The stored option wins whenever it exists, so a site that explicitly
	 * disabled the layer keeps that decision. When the option is absent the
	 * canonical default applies.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — resolve the flag from stored option then canonical default.
		if ( ! function_exists( 'get_option' ) ) {
			return self::is_enabled_default();
		}
		$flag = self::FEATURE_FLAG;
		$stored = get_option( $flag, null );
		if ( null === $stored || '' === $stored ) {
			return self::is_enabled_default();
		}
		return (bool) $stored;
	}

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
			'feature_flag' => self::FEATURE_FLAG,
			// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C — publish the canonical default so consumers do not re-declare it.
			'default_enabled' => self::is_enabled_default(),
			'log_contract_id' => 'core.context_bank.mpr_trace',
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
