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
			return array( 'ok' => false, 'reason' => 'context_bank_owner_scope_denied' );
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
}