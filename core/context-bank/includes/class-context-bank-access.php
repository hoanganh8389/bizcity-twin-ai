<?php
/**
 * Server-side Context Bank read authorization.
 *
 * Admins can inspect the current tenant. Other users are restricted to
 * pointer rows owned by the authenticated WordPress user; request filters
 * never establish ownership.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Access', false ) ) {
	return;
}

final class BizCity_Context_Bank_Access {

	const READ_CAPABILITY = 'read';

	/**
	 * Constrain a ledger filter set to the authenticated server-side owner.
	 *
	 * @param array<string,mixed> $filters Posted or internal filters.
	 * @return array<string,mixed>
	 */
	public static function scope_filters( array $filters ) {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — derive owner scope from the authenticated request, never from posted IDs.
		if ( self::is_admin() ) {
			return array( 'ok' => true, 'filters' => $filters, 'scope' => 'tenant_admin' );
		}
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 || ! self::can_read() ) {
			return array( 'ok' => false, 'reason' => 'context_bank_read_denied' );
		}
		if ( isset( $filters['wp_user_id'] ) && (int) $filters['wp_user_id'] > 0 && (int) $filters['wp_user_id'] !== $user_id ) {
			return array( 'ok' => false, 'reason' => 'context_bank_owner_scope_denied' );
		}
		if ( isset( $filters['user_id'] ) && (int) $filters['user_id'] > 0 && (int) $filters['user_id'] !== $user_id ) {
			return array( 'ok' => false, 'reason' => 'context_bank_owner_scope_denied' );
		}
		$channel_scope = self::channel_scope_from_filters( $filters, $user_id );
		if ( ! empty( $channel_scope['requested'] ) ) {
			if ( empty( $channel_scope['ok'] ) ) {
				return $channel_scope;
			}
			unset( $filters['wp_user_id'], $filters['user_id'] );
			return array( 'ok' => true, 'filters' => $filters, 'scope' => 'channel_grant', 'channel' => $channel_scope['channel'], 'account_key' => $channel_scope['account_key'] );
		}
		$filters['wp_user_id'] = $user_id;
		unset( $filters['user_id'] );
		return array( 'ok' => true, 'filters' => $filters, 'scope' => 'user' );
	}

	/**
	 * Authorize one pointer after it has been loaded from the current tenant.
	 *
	 * @param array<string,mixed> $pointer Ledger pointer row.
	 * @return array<string,mixed>
	 */
	public static function authorize_pointer( array $pointer ) {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — recheck tenant, capability and pointer owner immediately before file follow.
		$current_blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( $current_blog_id <= 0 || (int) ( $pointer['blog_id'] ?? 0 ) !== $current_blog_id ) {
			return array( 'ok' => false, 'reason' => 'context_bank_tenant_scope_denied' );
		}
		if ( self::is_admin() ) {
			return array( 'ok' => true, 'scope' => 'tenant_admin' );
		}
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 || ! self::can_read() ) {
			return array( 'ok' => false, 'reason' => 'context_bank_read_denied' );
		}
		if ( (int) ( $pointer['wp_user_id'] ?? 0 ) !== $user_id ) {
			$channel_scope = self::channel_scope_from_pointer( $pointer, $user_id );
			if ( empty( $channel_scope['ok'] ) ) {
				return array( 'ok' => false, 'reason' => 'context_bank_owner_scope_denied' );
			}
			return array( 'ok' => true, 'scope' => 'channel_grant', 'channel' => $channel_scope['channel'], 'account_key' => $channel_scope['account_key'] );
		}
		return array( 'ok' => true, 'scope' => 'user' );
	}

	public static function can_read() {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — require the authenticated WordPress read capability for non-admin Context Bank access.
		return function_exists( 'current_user_can' ) && current_user_can( self::READ_CAPABILITY );
	}

	public static function is_allowed_request() {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — expose only authenticated owner/admin requests to the REST permission callback.
		return self::is_admin() || self::can_read();
	}

	public static function is_admin_request() {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — keep destructive reconcile operations restricted to tenant administrators.
		return self::is_admin();
	}

	private static function is_admin() {
		// [2026-09-01 Johnny Chu] PHASE-CB-MVP — resolve administrative authority from the authenticated capability set.
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	private static function channel_scope_from_filters( array $filters, $user_id ) {
		$entity_type = sanitize_key( (string) ( $filters['entity_type'] ?? '' ) );
		$entity_key = trim( (string) ( $filters['entity_key'] ?? '' ) );
		if ( $entity_type !== 'channel_account' && $entity_key === '' ) {
			return array( 'requested' => false );
		}
		if ( $entity_type !== 'channel_account' || ! preg_match( '/^([a-z0-9_]+):(a_[a-f0-9]{64})$/i', $entity_key, $matches ) ) {
			return array( 'requested' => true, 'ok' => false, 'reason' => 'context_bank_channel_scope_invalid' );
		}
		$authorized = self::authorize_channel_scope( strtolower( $matches[1] ), strtolower( $matches[2] ), $user_id );
		$authorized['requested'] = true;
		return $authorized;
	}

	private static function channel_scope_from_pointer( array $pointer, $user_id ) {
		if ( (string) ( $pointer['entity_type'] ?? '' ) !== 'channel_account' ) {
			return array( 'ok' => false, 'reason' => 'context_bank_owner_scope_denied' );
		}
		$entity_key = trim( (string) ( $pointer['entity_key'] ?? '' ) );
		if ( ! preg_match( '/^([a-z0-9_]+):(a_[a-f0-9]{64})$/i', $entity_key, $matches ) ) {
			return array( 'ok' => false, 'reason' => 'context_bank_channel_scope_invalid' );
		}
		return self::authorize_channel_scope( strtolower( $matches[1] ), strtolower( $matches[2] ), $user_id );
	}

	private static function authorize_channel_scope( $channel, $account_key, $user_id ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — bridge exact Context Bank account scope to the Channel Gateway grant owner immediately before follow.
		if ( ! class_exists( 'BizCity_Channel_User_Grant' ) || ! method_exists( 'BizCity_Channel_User_Grant', 'authorize_account_key' ) ) {
			return array( 'ok' => false, 'reason' => 'channel_grant_owner_unavailable' );
		}
		$authorized = BizCity_Channel_User_Grant::authorize_account_key( $channel, $account_key, (int) $user_id, 'view_context' );
		if ( empty( $authorized['ok'] ) ) {
			return array( 'ok' => false, 'reason' => (string) ( $authorized['reason'] ?? 'context_bank_channel_scope_denied' ) );
		}
		return array( 'ok' => true, 'channel' => $channel, 'account_key' => $account_key, 'grant' => $authorized );
	}
}